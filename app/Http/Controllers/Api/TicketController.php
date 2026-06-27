<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use Illuminate\Http\Request;

class TicketController extends Controller
{
    public function pending(Request $request)
    {
        $query = Ticket::pending();

        if ($request->filled('missdn')) {
            $query->searchMissdn($request->missdn);
        }

        if ($request->filled('governorate')) {
            $query->filterGovernorate($request->governorate);
        }

        return response()->json([
            'success' => true,
            'tickets' => $query->with('creator')->get(),
        ]);
    }

    public function completed(Request $request)
    {
        if ($request->user()->role !== 'user') {
            return response()->json([
                'success' => false,
                'message' => 'Only users can view completed tickets',
            ], 403);
        }

        $query = Ticket::complete();

        if ($request->filled('missdn')) {
            $query->searchMissdn($request->missdn);
        }

        if ($request->filled('governorate')) {
            $query->filterGovernorate($request->governorate);
        }

        return response()->json([
            'success' => true,
            'tickets' => $query->with(['creator', 'completer'])->get(),
        ]);
    }

    public function store(Request $request)
    {
        if ($request->user()->role !== 'user') {
            return response()->json([
                'success' => false,
                'message' => 'Only users can create tickets',
            ], 403);
        }

        $request->validate([
            'missdn' => 'required|digits:10|unique:tickets',
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

        return response()->json([
            'success' => true,
            'message' => 'Ticket created successfully',
            'ticket' => $ticket->load('creator'),
        ], 201);
    }

    public function markComplete(Request $request, $id)
    {
        if ($request->user()->role !== 'staff') {
            return response()->json([
                'success' => false,
                'message' => 'Only staff can mark tickets as complete',
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

        return response()->json([
            'success' => true,
            'message' => 'Ticket marked as complete',
            'ticket' => $ticket->load(['creator', 'completer']),
        ]);
    }

    public function update(Request $request, $id)
    {
        $ticket = Ticket::findOrFail($id);

        if ($request->user()->id !== $ticket->created_by && $request->user()->role !== 'super_admin') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        $request->validate([
            'missdn' => 'digits:10|unique:tickets,missdn,' . $id,
            'governorate' => 'string',
            'comments' => 'nullable|string|max:500',
            'alwaseet_company' => 'nullable|string|max:500',
            'status' => 'in:Pending,Complete',
        ]);

        $ticket->update($request->only([
            'missdn',
            'governorate',
            'comments',
            'alwaseet_company',
            'status',
        ]));

        return response()->json([
            'success' => true,
            'message' => 'Ticket updated successfully',
            'ticket' => $ticket,
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
