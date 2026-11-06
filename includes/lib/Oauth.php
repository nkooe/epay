<?php
namespace lib;
/*
 * 快捷登录接口
 */

class Oauth{
	private $apiurl;
	private $appid;
	private $appkey;
	private $callback;

	function __construct($config){
		$this->apiurl = $config['apiurl'].'connect.php';
		$this->appid = $config['appid'];
		$this->appkey = $config['appkey'];
		$this->callback = $config['callback'];
	}

	//获取登录跳转url
	public function login($type){

		//-------生成唯一随机串防CSRF攻击
		$state = bin2hex(random_bytes(32));
		$_SESSION['Oauth_state'] = $state;

		//-------构造请求参数列表
		$keysArr = array(
			"act" => "login",
			"appid" => $this->appid,
			"appkey" => $this->appkey,
			"type" => $type,
			"redirect_uri" => $this->callback,
			"state" => $state
		);
		$login_url = $this->apiurl.'?'.http_build_query($keysArr);
		$response = get_curl($login_url);
		$arr = json_decode($response,true);
		return $arr;
	}

	//登录成功返回网站
	public function callback(){
		$state=$_GET['state']??null;$expected=$_SESSION['Oauth_state']??null;
		if(!is_string($state)||!is_string($expected)||$expected===''||!hash_equals($expected,$state)){
			return ['code'=>-1,'msg'=>'登录状态校验失败，请重新发起登录'];
		}
		unset($_SESSION['Oauth_state']);

		//-------请求参数列表
		$keysArr = array(
			"act" => "callback",
			"appid" => $this->appid,
			"appkey" => $this->appkey,
			"code" => $_GET['code']
		);

		//------构造请求access_token的url
		$token_url = $this->apiurl.'?'.http_build_query($keysArr);
		$response = get_curl($token_url);

		$arr = json_decode($response,true);
		return $arr;
	}

	//查询用户信息
	public function query($type, $social_uid){
		//-------请求参数列表
		$keysArr = array(
			"act" => "query",
			"appid" => $this->appid,
			"appkey" => $this->appkey,
			"type" => $type,
			"social_uid" => $social_uid
		);

		//------构造请求access_token的url
		$token_url = $this->apiurl.'?'.http_build_query($keysArr);
		$response = get_curl($token_url);

		$arr = json_decode($response,true);
		return $arr;
	}
}
