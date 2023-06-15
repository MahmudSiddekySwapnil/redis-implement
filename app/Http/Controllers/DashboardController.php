<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use app\models\TrxHistory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class DashboardController extends Controller
{

    public function getCurrentMonthTrxVolume()
    {
        $now = Carbon::now();
        $weekStartDay = $now->startOfWeek(Carbon::SATURDAY)->format('Y-m-d');
        $weekEndDay = $now->endOfWeek(Carbon::FRIDAY)->format('Y-m-d');
        $start_date = date("Y-m-01"); //the first day of current month
        $end_date = date("Y-m-t", strtotime($start_date));

        $value = Redis::get('this-month-total-trx-volume');
         if($value === null){
             $data= DB::table('trx_history')
                 ->select(DB::raw('count(inv_id) as count, round(sum(amount_recived)) as total'))
                 ->whereBetween(DB::raw('date(created_at)'), [$start_date, $end_date])
                 ->where('sp_code', 1000)
                 ->get();
             Redis::set('this-month-total-trx-volume', $data);
             return $data;
         }
        return $value;

    }

//    public function getCurrentMonthTrxVolume()
//    {
//        $now = Carbon::now();
//        $weekStartDay = $now->startOfWeek(Carbon::SATURDAY)->format('Y-m-d');
//        $weekEndDay = $now->endOfWeek(Carbon::FRIDAY)->format('Y-m-d');
//        $start_date = date("Y-m-01"); // The first day of the current month
//        $end_date = date("Y-m-t", strtotime($start_date));
//
//        // Check if the value exists in Redis cache
//        $value = Redis::get('this-month-total-trx-volume');
//
//        if ($value === null) {
//            // Value does not exist in Redis, fetch it from the database
//            $data = DB::table('trx_history')
//                ->select(DB::raw('count(inv_id) as count, round(sum(amount_received)) as total'))
//                ->whereBetween(DB::raw('date(created_at)'), [$start_date, $end_date])
//                ->where('sp_code', 1000)
//                ->get();
//
//            // Store the fetched value in Redis for future use
//            Redis::set('this-month-total-trx-volume', $data);
//
//            // Return the fetched value
//            return $data;
//        }
//
//        // Value exists in Redis, return it directly
//        return $value;
//    }

}
