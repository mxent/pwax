{{--
    A worked push-endpoint controller, as both a starting point and a checklist.

    The browser POSTs the `PushSubscription.toJSON()` shape on every subscribe, and
    the same shape's `endpoint` on every unsubscribe. Validating that exact shape
    is what stops a third party from planting a subscription on a real user — the
    body has to match before the row is written.

    The version that ships with `pwax:push-endpoint` is shorter; this one is the
    annotated version, the one a developer reads once and then replaces with the
    command's output.
--}}
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class PushSubscriptionController extends Controller
{
    public function subscribe(Request $request)
    {
        $data = $request->validate([
            'endpoint' => ['required', 'url'],
            'keys.p256dh' => ['required', 'string'],
            'keys.auth' => ['required', 'string'],
        ]);

        // Upsert on the endpoint so a user subscribing twice (different device,
        // refreshed permissions, subscription expiry and reissue) keeps one row.
        $request->user()->pushSubscriptions()->updateOrCreate(
            ['endpoint' => $data['endpoint']],
            ['p256dh' => $data['keys']['p256dh'], 'auth' => $data['keys']['auth']],
        );

        return response()->noContent();
    }

    public function unsubscribe(Request $request)
    {
        $request->user()->pushSubscriptions()
            ->where('endpoint', $request->input('endpoint'))
            ->delete();

        return response()->noContent();
    }
}
