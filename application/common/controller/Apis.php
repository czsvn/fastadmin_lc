<?php

namespace app\common\controller;

use app\common\library\Auth;
use think\Config;
use think\exception\HttpResponseException;
use think\exception\ValidateException;
use think\Hook;
use think\Lang;
use think\Loader;
use think\Request;
use think\Response;

/**
 * API控制器基类
 * @author Mars
 * 需要登录的接口加入防止参数修改
 * 需要登录的接口加入防止请求超时
 * 需要登录的接口加入防止请求重复
 */
class Apis
{
    
    /**
     * @var Request Request 实例
     */
    protected $request;
    
    /**
     * @var bool 验证失败是否抛出异常
     */
    protected $failException = false;
    
    /**
     * @var bool 是否批量验证
     */
    protected $batchValidate = false;
    
    /**
     * @var array 前置操作方法列表
     */
    protected $beforeActionList = [];
    
    /**
     * 无需登录的方法,同时也就不需要鉴权了
     * @var array
     */
    protected $noNeedLogin = [];
    
    /**
     * 无需鉴权的方法,但需要登录
     * @var array
     */
    protected $noNeedRight = [];
    
    /**
     * 权限Auth
     * @var Auth
     */
    protected $auth = null;
    
    /**
     * 默认响应输出类型,支持json/xml
     * @var string
     */
    protected $responseType = 'json';
    
    /**
     * 默认不检测接口传入的必须字段
     * @var boolean
     */
    protected $checkParameters = true;
    
    /**
     * 检查参数时必须的几个字段
     * @var array
     */
    protected $checkDatas = ['timestamp', 'token', 'signature', 'nonce'];
    
    /**
     * 用于签名需要过滤的字段
     * @var string
     */
    protected $signatureStr = "signature";
    
    /**
     * 签名的有效时间过期会失效防止别人盗用 防止一段时间内相同请求的重复提交
     * @var integer
     */
    protected $signExpire = 500;
    
    /**
     * 构造方法
     * @access public
     * @param Request $request Request 对象
     */
    public function __construct(Request $request = null)
    {
        $this->request = is_null($request) ? Request::instance() : $request;
        
        // 控制器初始化
        $this->_initialize();
        
        // 前置操作方法
        if ($this->beforeActionList) {
            foreach ($this->beforeActionList as $method => $options) {
                is_numeric($method) ?
                $this->beforeAction($options) :
                $this->beforeAction($method, $options);
            }
        }
    }
    
    /**
     * 初始化操作
     * @access protected
     */
    protected function _initialize()
    {
        //移除HTML标签
        $this->request->filter('trim,strip_tags,htmlspecialchars');
        
        $this->auth = Auth::instance();
        
        $modulename = $this->request->module();
        $controllername = strtolower($this->request->controller());
        $actionname = strtolower($this->request->action());
        
        // token
        $token = $this->request->server('HTTP_TOKEN', $this->request->request('token', \think\Cookie::get('token')));
        
        $path = str_replace('.', '/', $controllername) . '/' . $actionname;
        // 设置当前请求的URI
        $this->auth->setRequestUri($path);
        // 检测是否需要验证登录
        if (!$this->auth->match($this->noNeedLogin)) {
            //测试时开启
//             if ($this->checkParameters) {
//                 $this->filterParameters();
//             }
            //初始化
            $this->auth->init($token);
            //检测是否登录
            if (!$this->auth->isLogin()) {
                $this->error(__('Please login first'), null, 401);
            }
            // 判断是否需要验证权限
            if (!$this->auth->match($this->noNeedRight)) {
                // 判断控制器和方法判断是否有对应权限
                if (!$this->auth->check($path)) {
                    $this->error(__('You have no permission'), null, 403);
                }
            }
            
            //登录后进行参数检查处理
            if ($this->checkParameters) {
                $this->filterParameters();
            }
        } else {
            // 如果有传递token才验证是否登录状态
            if ($token) {
                $this->auth->init($token);
            }
        }
        
        $upload = \app\common\model\Config::upload();
        
        // 上传信息配置后
        Hook::listen("upload_config_init", $upload);
        
        Config::set('upload', array_merge(Config::get('upload'), $upload));
        
        // 加载当前控制器语言包
        $this->loadlang($controllername);
    }
    
    /**
     * 过滤输入防止数据更改和重放
     */
    protected function filterParameters()
    {
        $timestamp = $this->request->request('timestamp');
        $token = $this->request->request('token');
        $nonce = $this->request->request('nonce');
        $signature = $this->request->request('signature');
        if (!$timestamp || !$token || !$nonce || !$signature) {
            $this->error(__('缺少必要参数'), null, 503);
        }
        $queryParameters = array_filter($this->request->request(), [$this, 'filter'], ARRAY_FILTER_USE_KEY);
        ksort($queryParameters, SORT_NATURAL);
//         var_dump($queryParameters);
//         var_dump(json_encode($queryParameters));
        $sign = strtoupper(md5(json_encode($queryParameters)));
//         var_dump($sign);
        //校验数据合法
        if ($sign !== $signature) {
            $this->error("参数错误", null, 504);
        }
        //时效检测
//         var_dump(time() - $timestamp);
        if ($timestamp < time() - $this->signExpire) {
            $this->error("请求已过期", null, 505);
        }
        //重放检测
        $this->defendRepeat($sign);
    }
    
    /**
     * 过滤签名字段
     * @param string $name
     * @return boolean
     */
    protected function filter($name)
    {
        if ($this->signatureStr) {
            return $name !== $this->signatureStr;
        }
        return $name !== "signature";
    }
    
    /**
     * 防止同一请求重复提交nonce控制重复
     * @param unknown $sign
     */
    protected function defendRepeat($sign)
    {
//         \think\Cache::rm($sign);
        $signed = \think\Cache::get($sign);
        if ($signed) {
            $this->error("请勿重复提交", null, 555);
        } else {
            \think\Cache::set($sign, 1, $this->signExpire);
        }
    }
    /**
     * 加载语言文件
     * @param string $name
     */
    protected function loadlang($name)
    {
        Lang::load(APP_PATH . $this->request->module() . '/lang/' . $this->request->langset() . '/' . str_replace('.', '/', $name) . '.php');
    }
    
    /**
     * 操作成功返回的数据
     * @param string $msg    提示信息
     * @param mixed  $data   要返回的数据
     * @param int    $code   错误码，默认为1
     * @param string $type   输出类型
     * @param array  $header 发送的 Header 信息
     */
    protected function success($msg = '', $data = null, $code = 1, $type = null, array $header = [])
    {
        $this->result($msg, $data, $code, $type, $header);
    }
    
    /**
     * 操作失败返回的数据
     * @param string $msg    提示信息
     * @param mixed  $data   要返回的数据
     * @param int    $code   错误码，默认为0
     * @param string $type   输出类型
     * @param array  $header 发送的 Header 信息
     */
    protected function error($msg = '', $data = null, $code = 0, $type = null, array $header = [])
    {
        $this->result($msg, $data, $code, $type, $header);
    }
    
    /**
     * 返回封装后的 API 数据到客户端
     * @access protected
     * @param mixed  $msg    提示信息
     * @param mixed  $data   要返回的数据
     * @param int    $code   错误码，默认为0
     * @param string $type   输出类型，支持json/xml/jsonp
     * @param array  $header 发送的 Header 信息
     * @return void
     * @throws HttpResponseException
     */
    protected function result($msg, $data = null, $code = 0, $type = null, array $header = [])
    {
        $result = [
            'code' => $code,
            'msg'  => $msg,
            'time' => Request::instance()->server('REQUEST_TIME'),
            'data' => $data,
        ];
        // 如果未设置类型则自动判断
        $type = $type ? $type : ($this->request->param(config('var_jsonp_handler')) ? 'jsonp' : $this->responseType);
        
        if (isset($header['statuscode'])) {
            $code = $header['statuscode'];
            unset($header['statuscode']);
        } else {
            //未设置状态码,根据code值判断
            $code = $code >= 1000 || $code < 200 ? 200 : $code;
        }
        $response = Response::create($result, $type, $code)->header($header);
        throw new HttpResponseException($response);
    }
    
    /**
     * 前置操作
     * @access protected
     * @param  string $method  前置操作方法名
     * @param  array  $options 调用参数 ['only'=>[...]] 或者 ['except'=>[...]]
     * @return void
     */
    protected function beforeAction($method, $options = [])
    {
        if (isset($options['only'])) {
            if (is_string($options['only'])) {
                $options['only'] = explode(',', $options['only']);
            }
            
            if (!in_array($this->request->action(), $options['only'])) {
                return;
            }
        } elseif (isset($options['except'])) {
            if (is_string($options['except'])) {
                $options['except'] = explode(',', $options['except']);
            }
            
            if (in_array($this->request->action(), $options['except'])) {
                return;
            }
        }
        
        call_user_func([$this, $method]);
    }
    
    /**
     * 设置验证失败后是否抛出异常
     * @access protected
     * @param bool $fail 是否抛出异常
     * @return $this
     */
    protected function validateFailException($fail = true)
    {
        $this->failException = $fail;
        
        return $this;
    }
    
    /**
     * 验证数据
     * @access protected
     * @param  array        $data     数据
     * @param  string|array $validate 验证器名或者验证规则数组
     * @param  array        $message  提示信息
     * @param  bool         $batch    是否批量验证
     * @param  mixed        $callback 回调方法（闭包）
     * @return array|string|true
     * @throws ValidateException
     */
    protected function validate($data, $validate, $message = [], $batch = false, $callback = null)
    {
        if (is_array($validate)) {
            $v = Loader::validate();
            $v->rule($validate);
        } else {
            // 支持场景
            if (strpos($validate, '.')) {
                list($validate, $scene) = explode('.', $validate);
            }
            
            $v = Loader::validate($validate);
            
            !empty($scene) && $v->scene($scene);
        }
        
        // 批量验证
        if ($batch || $this->batchValidate) {
            $v->batch(true);
        }
        // 设置错误信息
        if (is_array($message)) {
            $v->message($message);
        }
        // 使用回调验证
        if ($callback && is_callable($callback)) {
            call_user_func_array($callback, [$v, &$data]);
        }
        
        if (!$v->check($data)) {
            if ($this->failException) {
                throw new ValidateException($v->getError());
            }
            
            return $v->getError();
        }
        
        return true;
    }
}
