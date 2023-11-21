<?php
/**
 * php artisan make:job UpdateProduct
 */

namespace App\Jobs;

use App\Events\OrderShipped;
use App\Exceptions\InternalException;
use App\Models\Activity;
use App\Models\Order;
use App\Services\RabbitmqService;
use Carbon\Carbon;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class GenerateOrder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    const PRE_ORDER_ID = "sec";
    const QUEUE = "sec-order";
    const ORDER_STATUS_UNPAY = 1; //未付款

    private $data;
    /**
     * UpdateProduct constructor.
     * @param $data
     * @throws \Exception
     */
    public function __construct($data)
    {
        $this->data = $data;
    }

    /**
     * 服务消费者会走到这里，把消息消费掉
     * php artisan queue:work rabbitmq
     * @throws \Exception
     */
    public function handle()
    {
        $data = $this->data;
        print_r($data);

        RabbitmqService::pop(self::QUEUE,function (){
            print_r('消费者开始消费消息'.PHP_EOL);
            $data = $this->data;
            print_r($data);
            // 启动事务
            DB::beginTransaction();
            // 捕获异常
            try{
                // 下单-初始化
                $order_info = [];
                $order_id = "sec".app('snowflake')->nextId();
                $order_info['order_id'] = self::PRE_ORDER_ID.$order_id;
                $order_info['product_id'] = $data['product_id'];
                $order_info['user_id'] = $data['user_id'];
                $order_info['buy_num'] = $data['buy_num'];
                $order_info['amount'] = $data['amount'];
                $order_info['payment_type'] = $data['payment_type'];
                $order_info['order_time'] = Carbon::now()->format('Y-m-d H:i:s');
                $order_info['order_status'] = self::ORDER_STATUS_UNPAY;
                Order::create($order_info);

                // 预占库存
                $this->updateStockNum($data['activity_id'], $data['buy_num']);

                //提交事务
                DB::commit();
                print_r('消息消费成功');
                return true;
            }catch(Exception $e) {
                DB::rollBack();
                print_r('消息消费失败'.$e->getMessage());
                //TODO 记录所有消费失败的订单入库
                return false;
            }

        });

    }

    /**
     * 更新库存
     * @throws InternalException
     */
    public function updateStockNum($activity_id, $buy_num): void
    {
        $activity = Activity::find($activity_id);
        if (!$activity) {
            throw new InternalException('库存数据丢失！');
        }

        $res = $activity->updateStockNum($buy_num);
        if (!$res) {
            //TODO 异步补偿处理：针对库存更新失败的情况，设计一个补偿机制，将更新失败的任务放入消息队列中，由后台任务异步进行补偿处理。
            throw new InternalException('更新库存失败！');
        }
    }
}

