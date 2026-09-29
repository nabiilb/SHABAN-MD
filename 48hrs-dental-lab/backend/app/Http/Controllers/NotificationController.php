<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Models\UserNotification;
use App\Support\Present;
use App\Support\Query;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** A user's own notifications. Other people's are invisible (404). */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = new Query($request);
        $mine = fn () => UserNotification::where('user_id', $request->user()->id);
        $query = $mine();
        if ($q->bool('unreadOnly')) {
            $query->whereNull('read_at');
        }
        $total = (clone $query)->count();
        $p = Query::paginate($q->page(), $total);
        $rows = $query->with('dentalCase:id,case_number')->orderByDesc('created_at')->orderByDesc('id')->offset($p['offset'])->limit($p['limit'])->get();

        return response()->json(['data' => $rows->map(fn ($n) => Present::notification($n))->all(), 'meta' => $p['meta'], 'unreadCount' => $mine()->whereNull('read_at')->count()]);
    }

    public function markRead(Request $request, string $id): Response
    {
        $mine = UserNotification::where('id', $id)->where('user_id', $request->user()->id);
        if (! (clone $mine)->whereNull('read_at')->update(['read_at' => now()->format('Y-m-d H:i:s.v')]) && ! $mine->exists()) {
            throw ApiException::notFound();
        }

        return response()->noContent();
    }

    public function markAllRead(Request $request): Response
    {
        UserNotification::where('user_id', $request->user()->id)->whereNull('read_at')->update(['read_at' => now()->format('Y-m-d H:i:s.v')]);

        return response()->noContent();
    }
}
