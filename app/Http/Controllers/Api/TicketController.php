<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TicketController extends Controller
{
    public function pending(Request $request)
    {
        return $this->respondWithList($request, Ticket::pending());
    }

    public function completed(Request $request)
    {
        return $this->respondWithList($request, Ticket::complete());
    }

    /**
     * Apply the shared filters (search / governorate / user / date range) and
     * return a paginated list with metadata.
     */
    private function respondWithList(Request $request, $query)
    {
        if ($request->filled('governorate')) {
            $query->filterGovernorate($request->governorate);
        }

        if ($request->filled('search')) {
            $term = $request->input('search');
            $query->where(function ($q) use ($term) {
                $q->where('missdn', 'like', "%{$term}%")
                    ->orWhere('comments', 'like', "%{$term}%")
                    ->orWhere('governorate', 'like', "%{$term}%")
                    ->orWhere('alwaseet_company', 'like', "%{$term}%")
                    ->orWhereHas('creator', fn ($c) => $c->where('name', 'like', "%{$term}%"))
                    ->orWhereHas('completer', fn ($c) => $c->where('name', 'like', "%{$term}%"));
            });
        }

        if ($request->filled('username')) {
            $name = $request->input('username');
            $query->whereHas('creator', fn ($c) => $c->where('name', 'like', "%{$name}%"));
        }

        // Accepts full ISO timestamps (UTC day boundaries from the client) or
        // plain dates; a datetime comparison keeps timezone handling correct.
        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->input('date_to'));
        }

        $perPage = (int) $request->input('per_page', 20);
        $perPage = $perPage > 0 ? min($perPage, 100) : 20;

        $paginator = $query->latest()->paginate($perPage);

        return response()->json([
            'success' => true,
            'tickets' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * Distinct users referenced as a ticket creator or completer — used to
     * populate the "filter by user" dropdown for any authenticated role.
     */
    public function filterUsers()
    {
        $creatorIds = Ticket::query()->distinct()->pluck('created_by');
        $completerIds = Ticket::query()->whereNotNull('completed_by')->distinct()->pluck('completed_by');
        $ids = $creatorIds->merge($completerIds)->unique()->filter()->values();

        $users = \App\Models\User::whereIn('id', $ids)
            ->orderBy('name')
            ->get(['id', 'name']);

        $governorates = Ticket::query()
            ->whereNotNull('governorate')
            ->where('governorate', '!=', '')
            ->distinct()
            ->orderBy('governorate')
            ->pluck('governorate')
            ->values();

        return response()->json([
            'success' => true,
            'users' => $users,
            'governorates' => $governorates,
        ]);
    }

    public function store(Request $request)
    {
        if (! in_array($request->user()->role, ['user', 'super_admin'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not allowed to create tickets',
            ], 403);
        }

        $request->validate([
            'missdn' => 'required|digits:10',
            'governorate' => 'required|string',
            'comments' => 'nullable|string|max:500',
            'status' => 'required|in:Pending,Complete',
        ]);

        $ticket = Ticket::create([
            'missdn' => $request->missdn,
            'governorate' => $request->governorate,
            'comments' => $request->comments,
            'status' => $request->status,
            'created_by' => $request->user()->id,
        ]);

        $this->logActivity($ticket, $request->user(), 'created');

        return response()->json([
            'success' => true,
            'message' => 'Ticket created successfully',
            'ticket' => $ticket->load('creator'),
        ], 201);
    }

    /**
     * Create many pending tickets in a single request (Excel import).
     */
    public function bulkStore(Request $request)
    {
        if (! in_array($request->user()->role, ['user', 'super_admin'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not allowed to create tickets',
            ], 403);
        }

        $request->validate([
            'tickets' => 'required|array|min:1|max:1000',
            'tickets.*.missdn' => 'required|digits:10',
            'tickets.*.governorate' => 'required|string',
            'tickets.*.comments' => 'nullable|string|max:500',
        ]);

        $created = DB::transaction(function () use ($request) {
            $rows = [];
            foreach ($request->input('tickets') as $row) {
                $ticket = Ticket::create([
                    'missdn' => $row['missdn'],
                    'governorate' => $row['governorate'],
                    'comments' => $row['comments'] ?? null,
                    'status' => 'Pending',
                    'created_by' => $request->user()->id,
                ]);
                $this->logActivity($ticket, $request->user(), 'created');
                $rows[] = $ticket;
            }
            return $rows;
        });

        return response()->json([
            'success' => true,
            'message' => 'Tickets imported successfully',
            'count' => count($created),
            'tickets' => $created,
        ], 201);
    }

    public function markComplete(Request $request, $id)
    {
        if (! in_array($request->user()->role, ['staff', 'super_admin'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not allowed to complete tickets',
            ], 403);
        }

        $ticket = Ticket::findOrFail($id);

        $request->validate([
            'alwaseet_company' => 'required|string|max:500',
        ]);

        $ticket->update([
            'status' => 'Complete',
            'completed_by' => $request->user()->id,
            'completed_at' => now(),
            'alwaseet_company' => $request->alwaseet_company,
        ]);

        $this->logActivity($ticket, $request->user(), 'completed');

        return response()->json([
            'success' => true,
            'message' => 'Ticket marked as complete',
            'ticket' => $ticket->load(['creator', 'completer']),
        ]);
    }

    /**
     * The ticket creator answers a reopened ticket; status becomes "Replied"
     * so staff can see the user has responded.
     */
    public function reply(Request $request, $id)
    {
        $ticket = Ticket::findOrFail($id);
        $user = $request->user();

        if ($user->id !== $ticket->created_by && $user->role !== 'super_admin') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        if ($ticket->status !== 'Reopened') {
            return response()->json([
                'success' => false,
                'message' => 'Only reopened tickets can be replied to',
            ], 422);
        }

        $request->validate([
            'reply' => 'required|string|max:500',
        ]);

        $ticket->update(['status' => 'Replied']);
        $this->logActivity($ticket, $user, 'replied', ['reply' => trim($request->reply)]);

        return response()->json([
            'success' => true,
            'message' => 'Reply sent',
            'ticket' => $ticket->load(['creator', 'completer']),
        ]);
    }

    public function update(Request $request, $id)
    {
        $ticket = Ticket::findOrFail($id);
        $user = $request->user();

        if ($user->role === 'super_admin') {
            // Super admin can edit any ticket, all fields.
            $request->validate([
                'missdn' => 'digits:10',
                'governorate' => 'string',
                'comments' => 'nullable|string|max:500',
                'alwaseet_company' => 'nullable|string|max:500',
                'status' => 'in:Pending,Complete,Reopened,Replied',
                'reopen_reason' => 'nullable|string|max:500',
            ]);

            $data = $request->only(['missdn', 'governorate', 'comments', 'alwaseet_company', 'status']);
            $this->applyCompletionMeta($data, $ticket, $user);
        } elseif ($user->role === 'staff') {
            // Staff can only change the status and write the Alwaseet Company; no content edits.
            $request->validate([
                'alwaseet_company' => 'nullable|string|max:500',
                'status' => 'in:Pending,Complete,Reopened,Replied',
                'reopen_reason' => 'nullable|string|max:500',
            ]);

            $data = $request->only(['alwaseet_company', 'status']);
            $this->applyCompletionMeta($data, $ticket, $user);
        } elseif ($user->role === 'user') {
            // Users can only edit the content of their own tickets, never the status.
            if ($user->id !== $ticket->created_by) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 403);
            }

            // A completed ticket can no longer be edited by the user who created it.
            if ($ticket->status === 'Complete') {
                return response()->json([
                    'success' => false,
                    'message' => 'Completed tickets cannot be edited',
                ], 403);
            }

            $request->validate([
                'missdn' => 'digits:10',
                'governorate' => 'string',
                'comments' => 'nullable|string|max:500',
            ]);

            $data = $request->only(['missdn', 'governorate', 'comments']);
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        $ticket->update($data);
        $this->logUpdateActivity($ticket, $user, $request->input('reopen_reason'));

        return response()->json([
            'success' => true,
            'message' => 'Ticket updated successfully',
            'ticket' => $ticket->load(['creator', 'completer']),
        ]);
    }

    /**
     * Record timeline events for an update: a status transition
     * (completed / reopened) or a content edit.
     */
    private function logUpdateActivity(Ticket $ticket, $user, ?string $reopenReason = null): void
    {
        if ($ticket->wasChanged('status')) {
            if ($ticket->status === 'Complete') {
                $this->logActivity($ticket, $user, 'completed');
            } elseif ($ticket->status === 'Reopened') {
                $reason = $reopenReason ? trim($reopenReason) : null;
                $this->logActivity($ticket, $user, 'reopened', $reason ? ['reason' => $reason] : null);
            }

            // A status change is its own event; don't also log it as a content edit.
            return;
        }

        $contentFields = ['missdn', 'governorate', 'comments', 'alwaseet_company'];
        $changed = array_values(array_intersect($contentFields, array_keys($ticket->getChanges())));

        if (! empty($changed)) {
            $this->logActivity($ticket, $user, 'edited', ['fields' => $changed]);
        }
    }

    private function logActivity(Ticket $ticket, $user, string $action, ?array $changes = null): void
    {
        TicketActivity::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user?->id,
            'action' => $action,
            'changes' => $changes,
        ]);
    }

    /**
     * Keep completion metadata in sync when status is toggled.
     */
    private function applyCompletionMeta(array &$data, Ticket $ticket, $user): void
    {
        if (! array_key_exists('status', $data)) {
            return;
        }

        // Sending a completed ticket back to work gets a distinct "Reopened"
        // status instead of plain "Pending".
        if ($ticket->status === 'Complete' && $data['status'] === 'Pending') {
            $data['status'] = 'Reopened';
        }

        if ($data['status'] === 'Complete' && $ticket->status !== 'Complete') {
            $data['completed_by'] = $user->id;
            $data['completed_at'] = now();
        } elseif (in_array($data['status'], ['Pending', 'Reopened', 'Replied'], true)) {
            $data['completed_by'] = null;
            $data['completed_at'] = null;
        }
    }

    public function destroy(Request $request, $id)
    {
        if ($request->user()->role !== 'super_admin') {
            return response()->json([
                'success' => false,
                'message' => 'Only super admins can delete tickets',
            ], 403);
        }

        $ticket = Ticket::findOrFail($id);
        $ticket->delete();

        return response()->json([
            'success' => true,
            'message' => 'Ticket deleted successfully',
        ]);
    }

    public function governorates()
    {
        return response()->json([
            'success' => true,
            'governorates' => [
                'Baghdad', 'Basra', 'Mosul', 'Kirkuk', 'Erbil',
                'Sulaymaniyah', 'Duhok', 'Anbar', 'Salah ad-Din',
                'Diyala', 'Wasit', 'Babylon', 'Karbala', 'Najaf',
                'Muthanna', 'Dhiqar', 'Maysan', 'Nineveh',
            ],
        ]);
    }
}
