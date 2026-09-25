<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Helpers\AuditLogger;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    public function index()
    {
        $settings = Setting::all()->groupBy('group');
        return response()->json($settings);
    }

    public function update(Request $request)
    {
        $data = $request->all(); // ['key' => 'value', ...]
        foreach ($data as $key => $value) {
            Setting::set($key, $value);
        }
        AuditLogger::log('UPDATE_SETTINGS', 'Settings', null, [], $data);
        return response()->json(['message' => 'Settings saved']);
    }

    public function get(string $key)
    {
        return response()->json(['key' => $key, 'value' => Setting::get($key)]);
    }
}
