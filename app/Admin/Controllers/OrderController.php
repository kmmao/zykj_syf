<?php

namespace App\Admin\Controllers;

use App\Models\Order;

use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use App\Http\Controllers\Controller;
use Encore\Admin\Controllers\ModelForm;
use Illuminate\Support\Facades\Request;
use App\Admin\Extensions\Tools\OrderStatus;
use Encore\Admin\Widgets\Table;
use App\Http\Requests\Admin\HandleRefundRequest;

class OrderController extends Controller
{
    use ModelForm;

    /**
     * Index interface.
     *
     * @return Content
     */
    public function index()
    {
        return Admin::content(function (Content $content) {

            $content->header('订单管理');
            $content->description('列表');

            $content->body($this->grid());
        });
    }

    /**
     * Edit interface.
     *
     * @param $id
     * @return Content
     */
    public function edit($id)
    {
        return Admin::content(function (Content $content) use ($id) {

            $content->header('订单管理');
            $content->description('编辑');

            $content->body($this->form()->edit($id));
        });
    }

    /**
     * Create interface.
     *
     * @return Content
     */
    public function create()
    {
        return Admin::content(function (Content $content) {

            $content->header('订单管理');
            $content->description('添加');

            $content->body($this->form());
        });
    }

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        return Admin::grid(Order::class, function (Grid $grid) {

            if (in_array(Request::get('status'), ['0', '1', '2', '3', '4', '5'])) {
                $grid->model()->where('status', Request::get('status'))->orderBy('created_at', 'desc');
            } else {
                $grid->model()->orderBy('created_at', 'desc');
            }

            $grid->id('ID')->sortable();
            $grid->column('users.username','收货信息')->display(function($user){
                return '下单人：'.$user
                .'<br/>收件人：'.$this->consignee
                .'<br/>电&nbsp;&nbsp;&nbsp;话：'.$this->phone
                .'<br/>地&nbsp;&nbsp;&nbsp;址：'.$this->address;
            });
            $grid->column('订单信息')->display(function(){
                return '总价：'.$this->tradetotal
                .'<br/>优惠：'.$this->preferentialtotal
                .'<br/>邮费：'.$this->customerfreightfee
                .'<br/>应付：'.$this->total
                .'<br/>已付：'.$this->paiedtotal;
            });
            $grid->status('订单状态')->display(function ($status){
                switch ($status) {
                  case 0:
                    $info = "<span class='label label-warning'>待支付</span>";
                    break;
                  case 1:
                    $info = "<span class='label label-primary'>已取消</span>";
                    break;
                  case 2:
                    $info = "<span class='label label-success'>待发货</span>";
                    break;
                  case 3:
                    $info = "<span class='label label-danger'>待收货</span>";
                    break;
                  case 4:
                    $info = "<span class='label label-info'>已收货</span>";
                    break;
                  case 5:
                    $info = "<span class='label label-info'>已完成</span>";
                    break;
                  default:
                    $info = "<span class='label label-warning'>待支付</span>";
                    break;
                }
                return $info;
            });
            $grid->column('订单详情')->expand(function () {
                $items = $this->items->toArray();
                $headers = ['ID', '商品ID', '商品名称', '规格', '数量', '商品单价','总计'];
                $title = ['id', 'product_id', 'title', 'norm', 'num',  'pre_price', 'total_price'];
                $datas = array_map(function ($item) use ($title) {
                    return array_only($item, $title);
                }, $items);
                return new Table($headers, $datas);
            }, '查看详情')->badge('red');
            $grid->created_at('创建时间');
            // $grid->updated_at('编辑时间');
            $grid->disableCreation();
            $grid->filter(function ($filter) {
                $filter->disableIdFilter();

                $filter->where(function ($query) {
                    $query->whereHas('users', function ($query) {
                        $query->where('username', 'like', "%{$this->input}%");
                    });
                }, '下单人');
                $filter->where(function ($query) {
                    $query->where('consignee', 'like', "%{$this->input}%")
                        ->orWhere('phone', 'like', "%{$this->input}%")
                        ->orWhere('address', 'like', "%{$this->input}%");
                }, '收件人或电话或地址');
                $filter->between('created_at', '下单时间')->datetime();
            });
            $grid->tools(function ($tools) {
                $tools->append(new OrderStatus());
            });
            $grid->actions(function ($actions) {
              $actions->disableDelete();
              $actions->disableEdit();
              $actions->append('<a href="/admin/manage/orders/'.$actions->getKey().'"><i class="fa fa-eye"></i></a>');
              // if ($actions->row->status == 0) {
              //   // 添加操作
              //   $actions->append('<a href="/admin/mamage/orders/'.$actions->getKey().'/show"><i class="fa fa-eye"></i></a>');
              //   $actions->append(new ApplyTool($actions->getKey(), 2));
              // }
            });

        });
    }

    /**
     * Make a form builder.
     *
     * @return Form
     */
    protected function form()
    {
        return Admin::form(Order::class, function (Form $form) {

            $form->display('id', 'ID');

            $form->display('created_at', 'Created At');
            $form->display('updated_at', 'Updated At');
        });
    }

    public function show(Order $order)
    {
        return Admin::content(function (Content $content) use ($order) {
            $content->header('查看订单');
            // body 方法可以接受 Laravel 的视图作为参数
            $content->body(view('admin.orders.show', ['order' => $order]));
        });
    }

    public function handleRefund(Order $order, HandleRefundRequest $request)
    {
        // 判断订单状态是否正确
        if ($order->refund_status !== Order::REFUND_STATUS_APPLIED) {
            throw new InvalidRequestException('订单状态不正确');
        }
        // 是否同意退款
        if ($request->agree) {
            // 同意退款的逻辑这里先留空
            $payment = \EasyWeChat::payment();

            $result = $payment->refund->byOutTradeNumber($order->out_trade_no, $order->out_refund_no, 1, 1, [
                'refund_desc' => $order->refund_reason,
            ]);
            $order->update([
                'refund_status' => Order::REFUND_STATUS_PROCESSING,//退款中
            ]);
			return $result;
        } else {
            // 将拒绝退款理由放到订单的 extra 字段中
            $extra = $order->extra ?: [];
            $extra['refund_disagree_reason'] = $request->reason;
            // 将订单的退款状态改为未退款
            $order->update([
                'refund_status' => Order::REFUND_STATUS_PENDING,
                'extra'         => $extra,
            ]);
        }

        return $order;
    }

    /**
    * 发货
    */
    public function send(Order $order, Request $request)
    {
        // 判断订单状态是否正确
        if ($order->status !== Order::ORDER_STATUS_PAYED) {
            throw new InvalidRequestException('订单状态不正确');
        }

        if ($request->ship_no) {
            // 将订单的状态改为待收货
            $order->update([
                'refund_status' => Order::ORDER_STATUS_SEND,
                'freightbillno'         => $request->ship_no,
            ]);
        }

        return $order;
    }
}
