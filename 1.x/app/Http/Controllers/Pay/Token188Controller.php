<?php
namespace App\Http\Controllers\Pay;

use App\Exceptions\AppException;
use App\Models\Pays;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Yansongda\Pay\Pay;

class Token188Controller extends PayController
{
    public function gateway($payway, $oid)
    {
        $this->checkOrder($payway, $oid);
		$params = [
            'merchantId' => $this->payInfo['merchant_id'],
            'outTradeNo' => $this->orderInfo['order_id'],
            'subject' => $this->orderInfo['order_id'],
            'totalAmount' => $this->orderInfo['actual_price'],
            'attach' => $this->orderInfo['actual_price'],
            'body' => $this->orderInfo['order_id'],
            'coinName' => 'USDT-TRC20',
            'notifyUrl' => site_url() . $this->payInfo['pay_handleroute'] . '/notify_url',
			'callBackUrl'=>site_url() . $this->payInfo['pay_handleroute'] . '/return_url?order_id=' . $this->orderInfo['order_id'],
            'timestamp' => $this->msectime(),
            'nonceStr' => $this->getNonceStr(16)
        ];
        
        //echo $params['totalAmount'];
        $mysign = self::GetSign($this->payInfo['merchant_pem'], $params);
        // 网关连接
        $ret_raw = self::_curlPost('https://api.token188.com/utg/pay/address', $params,$mysign,1);
		$ret = @json_decode($ret_raw, true);
		if($ret['rst']=='300'){
		    echo "获取参数失败，请重新提交";
		}else{
		    header("Location: ".$ret['data']['paymentUrl']);
		}
		
		
        exit;
    }

    public function notifyUrl(Request $request)
    {
        $content = file_get_contents('php://input');
		$json_param = json_decode($content, true); //convert JSON into array
        
        $cacheord = json_decode(Redis::hget('PENDING_ORDERS_LIST', $json_param['outTradeNo']), true);
        if (!$cacheord) {
            return 'fail';
        }
        
        $payInfo = Pays::where('id', $cacheord['pay_way'])->first();
		
		$coinPay_sign = $json_param['sign'];
		unset($json_param['sign']);
		unset($json_param['notifyId']);
		$sign = self::GetSign($payInfo['merchant_pem'], $json_param);
		if ($sign !== $coinPay_sign) {
			echo json_encode(['status' => 400]);
			return false;
		}
		$json_param['sign'] = $sign;

		// check request format
		if ($json_param['merchantId']!=$payInfo['merchant_id']) {
			echo json_encode(['status' => 401]);
			return false;
		}

           
        $out_trade_no = $json_param['outTradeNo'];
		// check payment status
		if ($json_param['tradeStatus'] === 'SUCCESS') {
			$pay_trade_no=$json_param['tradeNo'];
			$price=($json_param['originalAmount']);
			
            $this->orderService->successOrder($out_trade_no, $pay_trade_no, $price);
			

			echo 'success';
			die();
			return true;
		} 

		echo json_encode(['status' => 406]);
		return false;

    }
	private function _curlPost($url,$params=false,$signature,$ispost=0){
        
		$ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300); //设置超时
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE); // https请求 不验证证书和hosts
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt(
            $ch, CURLOPT_HTTPHEADER, array('token:'.$signature)
        );
        $result = curl_exec($ch);
        curl_close($ch);
        return $result;
    }
	public function GetSign($secret, $params)
    {
        $p=ksort($params);
        reset($params);

		if ($p) {
			$str = '';
			foreach ($params as $k => $val) {
				$str .= $k . '=' .  $val . '&';
			}
			$strs = rtrim($str, '&');
		}
		$strs .='&key='.$secret;
        
        $signature = md5($strs);

        //$params['sign'] = base64_encode($signature);
        return $signature;
    }
    public function msectime() {
		list($msec, $sec) = explode(' ', microtime());
		$msectime = (float)sprintf('%.0f', (floatval($msec) + floatval($sec)) * 1000);
		return $msectime;
    }
    /**
     * 返回随机字符串
     * @param int $length
     * @return string
     */
    public static function getNonceStr($length = 32)
    {
        $chars = "abcdefghijklmnopqrstuvwxyz0123456789";
        $str = "";
        for ($i = 0; $i < $length; $i++) {
            $str .= substr($chars, mt_rand(0, strlen($chars) - 1), 1);
        }
        return $str;
    }
}
