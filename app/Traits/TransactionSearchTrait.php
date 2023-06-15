<?php

namespace App\Traits;

use App\Models\TrxHistoryModel;
use Carbon\Carbon;
use DateTime;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use App\Models\RefundRequestModel;
use stdClass;


trait TransactionSearchTrait
{
    private $SETTLED = 1;

    /**
     * @param string $period
     * @param $mid
     * @param $store
     * @param $tx_status
     * @return array
     */
    public function searchByPeriod(string $period, $mid, $store, $tx_status): array
    {
        $toDate = Carbon::now();
        $fromDate = Carbon::now()->subDays($period);
        return $this->searchByDate($fromDate, $toDate, $mid, $store, $tx_status);
    }

    /**
     * Search transactions by date range, merchant ID, store ID, and transaction status,
     *and return an array of results including transaction details and additional information.
     * @param $fromDate
     * @param $toDate
     * @param $mid
     * @param $store
     * @param $tx_status
     * @return array
     */
    public function searchByDate($fromDate, $toDate, $mid, $store, $tx_status): array
    {
        $fieldNames = [
            'trx_history.bank_trx_id',
            'trx_history.invoice_no',
            'trx_history.customer_order_id',
            'trx_history.amount_recived',
            'trx_history.comm_total',
            'trx_history.rate_commission',
            'trx_history.rate_surcharge',
            'trx_history.comm_surcharge',
            'store_info.is_add_commission',
            'order_history.payable_amount',
            'trx_history.commission',
            'trx_history.currency',
            'payment_method.method_name',
            'trx_history.merchant_payable as merchant_payable',
            'trx_history.comm_total as commission_amount',
            'trx_history.is_settled',
            'trx_history.is_chargeback',
            'trx_history.sp_code',
            'trx_history.sp_massage',
            'trx_history.refund_code',
            'trx_history.created_at',
            'customer_info.cus_name',
            'customer_info.cus_phone'
        ];
        $query = DB::table('trx_history')->select($fieldNames);
        $query->leftJoin('in_order', 'trx_history.invoice_no', '=', 'in_order.order_id')
            ->leftJoin('payment_method', 'trx_history.payment_method', '=', 'payment_method.id')
            ->leftJoin('customer_info', 'trx_history.invoice_no', '=', 'customer_info.order_id')
            ->leftJoin('store_info', 'in_order.store_id', '=', 'store_info.id')
            ->leftJoin('order_history', 'trx_history.invoice_no', '=', 'order_history.invoice_no');
        $query->when($tx_status, function ($query, $tx_status) {
            $query->where('trx_history.sp_code', $tx_status);
        })->when($mid, function ($query, $mid) {
            $query->where('trx_history.mid', $mid);
        })->when($store, function ($query, $store) {
            $query->where('trx_history.store_id', $store);
        })->when((!empty($fromDate) && !empty($toDate)), function ($query) use ($fromDate, $toDate) {
            $query->whereBetween('trx_history.created_at', [$this->getStartOfTheDay($fromDate), $this->getEndOfTheDay($toDate)]);
        })->selectRaw('
                                             CASE
                                                      WHEN coalesce(trx_history.comm_surcharge, 0) >0  THEN trx_history.comm_surcharge
                                                      ELSE trx_history.comm_total
                                                    END
                                                   as commission_amount
        ');
        // TODO how to set collection
        // return $this->getTransactionSummaryFromCollection($query->get());
        return $query->get()->toArray();
    }

    public function getTransactionSummaryFromCollection(Collection $list)
    {
        return array(new TransactionSummary());
    }


    /**
     *Retrieves a collection of successful transactions for a given store
     * @param string $store
     * @param int $limit
     * @return Collection Array of TransactionBrief
     */
    public function getSuccessfulTransactions(string $store, int $limit = 10, string $merchantId): Collection
    {
        return DB::table('trx_history as th')
            ->select('th.invoice_no', 'th.amount_recived', 'th.currency', 'pm.method_name', 'th.created_at')
            ->leftJoin('in_order', 'th.invoice_no', '=', 'in_order.order_id')
            ->leftJoin('payment_method as pm', 'th.payment_method', '=', 'pm.id')
//            ->where('in_order.store_id', '=', $store)
            ->when($merchantId, function ($query, $merchantId) {
                $query->where('th.mid', $merchantId);
            })->when($store, function ($query, $store) {
                $query->where('th.store_id', $store);
            })
            ->where('sp_code', '=', SP_TX_SUCCESS)
            ->orderByDesc('th.created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Returns all settled transactions for a merchant store ordered by approval date.
     * @param $store_id
     * @return Collection
     */
    public function getTransactionsSettled($storeId, $merchantId): Collection
    {
        return DB::table('withdraw_request')
            ->select('tx_count', 'invoice_id', 'status', 'payable_amount', 'date_from', 'date_to', 'approved_at')
            ->when($merchantId, function ($query, $merchantId) {
                $query->where('merchant_id', $merchantId);
            })->when($storeId, function ($query, $storeId) {
                $query->where('store_id', $storeId);
            })
            ->where('status', '=', 2)
            ->orderBy('approved_at', 'desc')
            ->get();
    }

    public function transactionDetails(string $sp_order): ?TransactionDetails
    {
        return new TransactionDetails();
    }

    /**
     *  Returns the total count of transactions based on the provided filters
     * @param DateTime $fromDate
     * @param DateTime $toDate
     * @param array $stores
     * @param $tx_status
     * @return int
     */
    public function transactionCount($fromDate,$toDate,$storeId,$merchantId,$tx_status): int
    {
        return DB::table('trx_history')->select('inv_id')->when($tx_status, function ($query, $tx_status) {
            $query->where('trx_history.sp_code', $tx_status);
        }) ->when($merchantId, function ($query, $merchantId) {
            $query->where('trx_history.mid', $merchantId);
        })->when($storeId, function ($query, $storeId) {
            $query->where('trx_history.store_id', $storeId);
        })->when((!empty($fromDate) && !empty($toDate)), function ($query) use ($fromDate, $toDate) {
            $query->whereBetween('trx_history.created_at', [$this->getStartOfTheDay($fromDate), $this->getEndOfTheDay($toDate)]);
        })->get()->count();
    }

    /**
     * This method retrieves the total commission earned from transactions that match the given criteria,
     * such as a specific time range, store IDs, and transaction status.
     * It returns a stdClass containing a single row with the sum of the commission, which is calculated based on the commission or surcharge amounts associated with each transaction.
     * @param $fromDate
     * @param $toDateTime
     * @param array $stores
     * @param $tx_status
     * @return mixed
     */
    public function transactionCommission($fromDate, $toDate,$stores,$merchantId,$tx_status)
    {
       return $trxwithcomm=$this->transactionVolumeWithCommission($fromDate, $toDate,$stores,$merchantId,$tx_status)-$this->transactionVolumeWithoutCommission($fromDate, $toDate,$stores,$merchantId,$tx_status);
    }

    /**
     * This method retrieves refund details for a given transaction ID.
     * It joins multiple tables to gather information such as customer details, merchant details, payment method, and commission information.
     * @param $id
     * @return array
     */
    public function getRefundDetails($id): array
    {
        $transaction = DB::table('trx_history as th')
            ->select('si.is_add_commission', 'pm.gateway_type_id', 'io.merchant_id', 'io.store_id', 'th.amount_recived', 'th.bank_trx_id', 'th.invoice_no', 'ci.cus_name', 'ci.cus_email', 'ci.cus_address', 'ci.cus_phone', 'th.currency', 'mi.merchant_name', 'th.comm_total', 'th.comm_surcharge',)
            ->leftJoin('customer_info as ci', 'ci.order_id', '=', 'th.invoice_no')
            ->leftJoin('in_order as io', 'io.order_id', '=', 'th.invoice_no')
            ->leftJoin('merchant_info as mi', 'mi.id', '=', 'io.merchant_id')
            ->leftJoin('store_info as si', 'si.id', '=', 'io.store_id')
            ->leftJoin('payment_method as pm', 'pm.id', '=', 'th.payment_method')
            ->where('th.invoice_no', '=', $id)
            ->get();

        return json_decode(json_encode($transaction), true);

    }

    /**
     * This method calculates the transaction volume for the current month of a given store.
     * It gets the current week's start and end date, as well as the start and end date of
     * the current month. Then it queries the database for all successful transactions within
     * the current month for the given store and returns the total transaction amount.
     * @param $storeId
     * @return int|float|null
     */
    public function getCurrentMonthTrxVolume()
    {
        $now = Carbon::now();
        $weekStartDay = $now->startOfWeek(Carbon::SATURDAY)->format('Y-m-d');
        $weekEndDay = $now->endOfWeek(Carbon::FRIDAY)->format('Y-m-d');
        $start_date = date("Y-m-01"); //the first day of current month
        $end_date = date("Y-m-t", strtotime($start_date));
        return DB::table('trx_history')
            ->select(DB::raw('count(inv_id) as count, round(sum(amount_recived)) as total'))
            ->whereBetween(DB::raw('date(created_at)'), [$start_date, $end_date])
            ->where('sp_code', SP_TX_SUCCESS)
            ->get();

    }

    /**
     * This method retrieves the total transaction volume for the current month for a given store,
     * including commission. It achieves this by calling the transactionVolumeWithCommission method with
     * the start of the current month and the current date as the date range, and the given store ID and SP_TX_SUCCESS as
     * the transaction status. The method then returns the resulting transaction volume.
     * @param $storeId
     * @return float|int|null
     */
    public function getCurrentMonthTrxVolumeNew($storeId, $merchantId)
    {
        return $this->transactionVolumeWithCommission(Carbon::now(), Carbon::now()->startOfMonth(), $storeId, $merchantId, SP_TX_SUCCESS);
    }

    /**
     * This method retrieves the transaction volume with commission for the given stores and
     * transaction status within the given time period. It uses Laravel's query builder to construct
     * the SQL query. The commission amount is included in the returned transaction volume.
     * The $from and $toDateTime parameters specify the time range for the transactions,
     * while $stores is an array of store IDs to retrieve transactions for, and $tx_status is
     * the status of the transactions to retrieve.
     * @param $from
     * @param $toDateTime
     * @param array $stores
     * @param $tx_status
     * @return int|float|null
     */
    public function transactionVolumeWithCommission($from = '', $toDateTime = '', $stores, $merchantId, $tx_status = '')
    {
        return DB::table('trx_history')->when($stores, function ($query, $stores) {
            $query->where('trx_history.store_id', $stores);
        })->when($merchantId, function ($query, $merchantId) {
            $query->where('trx_history.mid', $merchantId);
        })->when($tx_status, function ($query, $tx_status) {
            $query->where('trx_history.sp_code', $tx_status);
        })->when(!empty($from) && !empty($toDateTime), function ($query) use ($from, $toDateTime) {
            $query->whereBetween('trx_history.created_at', [$this->getStartOfTheDay($from),$this->getEndOfTheDay($toDateTime)]);
        })->sum('trx_history.amount_recived');

    }

    /**
     * This method retrieves the transaction volume for a specific store for today's date,
     * including commission. It calls the transactionVolumeWithCommission() method with
     * the current date as the start date and the current time as the end date, and
     * passes the store ID and transaction status as parameters. The method then returns
     * the sum of the transaction amount received for the given time period. A comment has been added to
     * the code to describe the method.
     * @param $storeId
     * @return int|float|null
     */
    public function getTodayTrxVolume($storeId, $merchantId)
    {
        return $this->transactionVolumeWithCommission(Carbon::today(), Carbon::now(), $storeId, $merchantId, SP_TX_SUCCESS);
    }

    /**
     * This method calculates the total transaction volume with commission for the current week of a
     * specific store, using the transactionVolumeWithCommission method.
     * It first calculates the start and end dates of the current week based on the current date using
     * Carbon, and then passes these dates along with the store ID and transaction status as
     * parameters to the transactionVolumeWithCommission method. The method then returns the total
     * transaction volume with commission for the current week.
     * @param $storeId
     * @return int|float|null
     */
    public function getWeeklyTrxVolume($storeId, $merchantId)
    {
        $now = Carbon::now();
        $weekStartDay = $now->startOfWeek(Carbon::SATURDAY)->format('Y-m-d h:m:s');
        $weekEndDay = $now->endOfWeek(Carbon::FRIDAY)->format('Y-m-d h:m:s');
        return $this->transactionVolumeWithCommission($weekStartDay, $weekEndDay, $storeId, $merchantId, SP_TX_SUCCESS);
    }

    /**
     * This method retrieves the lifetime transaction volume of a given store by calling
     * the transactionVolumeWithCommission() method and passing empty strings as the $from and $toDateTime
     * parameters to fetch all transactions with a successful transaction status (SP_TX_SUCCESS) for
     * the specified store ($storeId). The method then returns the sum of the transaction amounts with commission.
     * @param $storeId
     * @return int|float|null
     */
    public function getLifetimeTrxVolume($storeId, $merchantId)
    {
        return $this->transactionVolumeWithCommission('', '', $storeId, $merchantId, SP_TX_SUCCESS);
    }

    /**
     * This method getLifetimePaidVolume() returns the total paid volume for the given store ID from
     * the beginning of time until now, by calling the getPaidVolume() method with
     * the necessary parameters. The getPaidVolume() method is responsible for fetching the sum of
     * the merchant payable amount for successful and settled transactions for the given store ID and
     * time period.
     * @param $storeId
     * @return float|int|null
     */
    public function getLifetimePaidVolume($storeId, $merchantId)
    {
        return $this->getPaidVolume('', '', $storeId, $merchantId, SP_TX_SUCCESS, $this->SETTLED);
    }

    /**
     * This method returns the total paid transaction volume, with a specified transaction status and settlement
     * status, within a specified date range and for specific store IDs.
     * It queries the 'trx_history' table and applies filters based on the given parameters.
     * The method uses Laravel's query builder 'when' method to conditionally apply the filters based on whether
     * the parameter has a value.
     * @param $from
     * @param $toDateTime
     * @param array $stores
     * @param $txStatus
     * @param $isSettled
     * @return int|float|null
     */
    public function getPaidVolume($from = '', $toDateTime = '', $stores, $merchantId, $txStatus = '', $isSettled)
    {
        return DB::table('trx_history')
            ->where('is_settled', $isSettled)
            ->when($merchantId, function ($query, $merchantId) {
                $query->where('trx_history.mid', $merchantId);
            })->when($stores, function ($query, $stores) {
                $query->where('trx_history.store_id', $stores);
            })->when($txStatus, function ($query, $tx_status) {
                $query->where('trx_history.sp_code', $tx_status);
            })->when(!empty($from) && !empty($toDateTime), function ($query) use ($from, $toDateTime) {
                $query->whereBetween('trx_history.created_at', [$this->getStartOfTheDay($from), $this->getEndOfTheDay($toDateTime)]);
            })->sum('trx_history.merchant_payable');
    }

    public function getCurrentBalanceVolumeNew($storeId, $merchantId)
    {
        return $this->currentBalanceVolume('', '', $storeId, $merchantId, SP_TX_SUCCESS);
    }

    /**
     * This method calculates the current balance volume by subtracting the withdrawal volume from
     * the transaction volume (without commission) and adding the refund volume within a
     * given time range and for specific stores and transaction status.
     * @param $from
     * @param $toDateTime
     * @param $stores
     * @param $tx_status
     * @return int|float|null
     */
    public function currentBalanceVolume($from, $toDateTime, $stores, $merchantId, $tx_status)
    {
        $volume=$this->withdrawalVolume($from, $toDateTime, $stores, $merchantId, $tx_status)+ $this->refundVolume($from, $toDateTime, $stores, $merchantId, $tx_status);
       return  $this->transactionVolumeWithoutCommission($from, $toDateTime, $stores, $merchantId, $tx_status) - $volume;

    }

    /**
     * This method returns the transaction volume without commission for a given time range and status,
     * for a list of specified stores. It queries the trx_history table and filters
     * the results based on the input parameters. It calculates the total transaction volume
     * without commission by summing up the merchant_payable field.
     * @param $from
     * @param $toDateTime
     * @param array $stores
     * @param $tx_status
     * @return int|float|null
     */
    public function transactionVolumeWithoutCommission($from, $toDateTime, $stores, $merchantId, $tx_status)
    {
        return DB::table('trx_history')->when($stores, function ($query, $stores) {
            $query->where('trx_history.store_id', $stores);
        })->when($merchantId, function ($query, $merchantId) {
            $query->where('trx_history.mid', $merchantId);
        })->when($tx_status, function ($query, $tx_status) {
            $query->where('trx_history.sp_code', $tx_status);
        })->when(!empty($from) && !empty($toDateTime), function ($query) use ($from, $toDateTime) {
            $query->whereBetween('trx_history.created_at', [$this->getStartOfTheDay($from), $this->getEndOfTheDay($toDateTime)]);
        })->sum('trx_history.merchant_payable');
    }

    /**
     * This method retrieves the total withdrawal volume for a given period, store, and transaction status.
     * It queries the trx_history and in_order tables and calculates the sum of merchant_payable field for all
     * the matching records. If there is no matching record found, it returns 0. The method takes four parameters: $from (start date),
     * $toDateTime (end date), $stores (array of store IDs), and $tx_status (transaction status).
     * @param $from
     * @param $toDateTime
     * @param $stores
     * @param $tx_status
     * @return int|float|null
     */
    public function withdrawalVolume($from, $toDateTime, $stores, $merchantId, $tx_status)
    {      //            ->where('io.store_id', '=', $stores)

        $totalWithdraw = DB::table('trx_history AS th')
            ->select(DB::raw('SUM(th.merchant_payable) AS withdrawAmount'))
            ->leftJoin('in_order AS io', 'th.invoice_no', '=', 'io.order_id')->when($stores, function ($query, $stores) {
                $query->where('io.store_id', $stores);
            })->when($merchantId, function ($query, $merchantId) {
                $query->where('io.merchant_id', $merchantId);
            })->where('th.sp_code', '=', $tx_status)
            ->where('th.is_settled', '=', 1)
            ->when((!empty($from) && !empty($toDateTime)), function ($query) use ($from, $toDateTime) {
                $query->whereBetween('th.created_at', [$this->getStartOfTheDay($from) , $this->getEndOfTheDay($toDateTime)]);
            })->get();
        return $totalWithdraw[0]->withdrawAmount ? $totalWithdraw[0]->withdrawAmount : 0;
    }

    /**
     * This method calculates the total refund volume for a given period of time and stores,
     * by querying the RefundRequestModel table for entries with a sp_code of SP_REFUND_DONE (indicating a successful refund), and summing
     * the amount field. It takes in four parameters: $from and $toDateTime for the time period, $stores for the store IDs,
     * and $tx_status for the transaction status.
     * @param $from
     * @param $toDateTime
     * @param array $stores
     * @param $tx_status
     * @return int|float|null
     */
    public function refundVolume($from, $toDateTime, $stores, $merchantId, $tx_status)
    {
        return RefundRequestModel::where('sp_code', SP_REFUND_DONE)
            ->when($stores, function ($query, $stores) {
                $query->where('store_id', $stores);
            })->when($merchantId, function ($query, $merchantId) {
                $query->where('merchant_id', $merchantId);
            })->when((!empty($from) && !empty($toDateTime)), function ($query) use ($from, $toDateTime) {
                $query->whereBetween('created_at', [$this->getStartOfTheDay($from), $this->getEndOfTheDay($toDateTime)]);
            })->sum('per_amount');
    }

    public function getMaxTrxVolumeInDays($days){
        return TrxHistoryModel::selectRaw("count(inv_id) as count, round(sum(amount_recived)) as total, date(created_at) as date")
            ->whereRaw("date(created_at) < (DATE(NOW()) + INTERVAL $days DAY)")
            ->where('sp_massage', 'Success')
            ->groupBy(DB::raw("date(created_at)"))
            ->orderBy(DB::raw("date(created_at)"), 'DESC')
            ->take($days)
            ->get();
    }
    public function getMaxTrxVolumeInHours($hours){
        return DB::table('trx_history')
            ->join('in_order', 'trx_history.invoice_no', '=', 'in_order.order_id')
            ->join('merchant_info', 'in_order.merchant_id', '=', 'merchant_info.id')
            ->join('payment_method', 'trx_history.payment_method', '=', 'payment_method.id')
            ->where('trx_history.created_at', '>', DB::raw('DATE_ADD(NOW(), INTERVAL - '.$hours.' HOUR)'))
            ->where('trx_history.sp_code', SP_TX_SUCCESS)
            ->selectRaw('COUNT(trx_history.inv_id) as count, MAX(trx_history.amount_recived) as max_amount, merchant_info.merchant_name, trx_history.created_at, in_order.order_id, payment_method.id as payment_method, payment_method.method_name')
            ->groupBy('merchant_id', 'in_order.store_id', 'created_at', 'order_id', 'payment_method.id', 'method_name')
            ->orderByDesc('max_amount')
            ->limit($hours)
            ->get();
    }


    /**
     * This is a method that formats a floating-point number to a string with two decimal places.
     * It takes a single parameter, $amount, which is the float number to be formatted.
     * If $amount is empty, the method returns the string "0.00". Otherwise,
     * it formats $amount with two decimal places using number_format() function and returns
     * the formatted string.
     * @param float $amount
     * @return string
     */
    public function format_number_2($amount): string
    {
        return empty($amount) ? 0.00 : number_format($amount, 2, '.', '');
    }

    public function format_number_4(float $amount): string
    {
        return number_format($amount, 4, '.', '');
    }


    public function fraction_2_digits(float $amount): float
    {
        return empty($amount) ? 0.00 : round($amount, 2, PHP_ROUND_HALF_UP);
    }

    public function fraction_4_digits(float $amount): float
    {
        return empty($amount) ? 0.00 : round($amount, 4, PHP_ROUND_HALF_UP);
    }

    public function getStartOfTheDay($fromDate)
    {
        return Carbon::parse($fromDate)->startOfDay()->toDateTimeString();
    }
    public function getEndOfTheDay($toDate)
    {
        return Carbon::parse($toDate)->endOfDay()->toDateTimeString();
    }
}
