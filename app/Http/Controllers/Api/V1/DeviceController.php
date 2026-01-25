<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Traits\HttpResponses;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class DeviceController extends Controller
{
    use HttpResponses;

    /**
     * Register or update a device token.
     */
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'device_id' => 'required|string',
            'push_token' => 'required|string',
            'platform' => 'required|in:android,ios',
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors(), $validator->errors()->first(), 422);
        }

        $device = Device::updateOrCreate(
            ['device_id' => $request->device_id],
            [
                'push_token' => $request->push_token,
                'platform' => $request->platform,
                'status' => 'active',
                'last_registered_at' => now(),
            ]
        );

        return $this->success($device, 'Appareil enregistré avec succès.');
    }

    /**
     * Unregister a device (set status to inactive).
     */
    public function unregister(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'device_id' => 'required|string',
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors(), $validator->errors()->first(), 422);
        }

        $device = Device::where('device_id', $request->device_id)->first();

        if ($device) {
            $device->update(['status' => 'inactive']);
            return $this->success(null, 'Appareil désinscrit avec succès.');
        }

        return $this->error(null, 'Appareil non trouvé.', 404);
    }
}
