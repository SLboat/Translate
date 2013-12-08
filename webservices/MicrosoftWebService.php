<?php
/**
 * Contains a class for querying external translation service.
 *
 * @file
 * @author Niklas Laxström
 * @copyright Copyright © 2010-2013 Niklas Laxström
 * @license GPL-2.0+
 */

/** 森亮号大船修改，临时兼容，微软的新认证方式
 * 需要在LocalSetting增加你的API设置：
 * $wgTranslateTranslationServices['Microsoft']['clientid'] = "you_app_id" ;
 * $wgTranslateTranslationServices['Microsoft']['clientSecret'] = "client secret" ;
 */
/**
 * Implements support for Microsoft translation api v2.
 * @see http://msdn.microsoft.com/en-us/library/ff512421.aspx
 * @ingroup TranslationWebService
 * @since 2013-01-01
 */

class MicrosoftWebService extends TranslationWebService
{
    //映射代码，应该是重整为一样的代码
    protected function mapCode($code)
    {
        $map = array(
            'zh-hant' => 'zh-CHT',
            'zh-hans' => 'zh-CHS',
            'zh-cn' => 'zh-CHS'
        );
        #wfErrorLog('查询了映射代码：'.$code."\n", '/tmp/wm.log' ); //调试信息
        return isset($map[$code]) ? $map[$code] : $code;
    }
    
    /*
     * 获得访问token.
     *
     * @参数 string $clientID     程序客户端ID
     * @参数 string $clientSecret 程序客户端ID密匙.
     *
     * @返回 字符串token.
     */
    protected function getMSTokens($clientID, $clientSecret)
    {
        //这是当前的验证地址
        $authUrl = "https://datamarket.accesscontrol.windows.net/v2/OAuth2-13/"; //验证地址
        
        $options = array(); //看起来定义它是必要的
        $options['method']   = 'POST';
        $options['timeout']  = $this->config['timeout']; //有趣的是，在class里看起来是共享的
        $options['postData'] = array(
            'grant_type' => "client_credentials", //验证地址
            'scope' => "http://api.microsofttranslator.com", //使用范围，因为这套api看起来是很全面的玩意
            'client_id' => $clientID,
            'client_secret' => $clientSecret
        );
        # 可能用的上再次转录 $url .= wfArrayToCgi($params);
        #wfErrorLog('工厂构造'.serialize($options), '/tmp/wm.log' ); //调试信息

        $req = MWHttpRequest::factory($authUrl, $options);

        #wfErrorLog('开始请求\n', '/tmp/wm.log' ); //调试信息        

        $status = $req->execute(); //看起来死在这里,问题是选项啥的
        #wfErrorLog('请求送出去了\n', '/tmp/wm.log' ); //调试信息

        if (!$status->isOK()) {
            #wfErrorLog('获得token死了咯\n', '/tmp/wm.log' ); //调试信息
            
            $error = $req->getContent();
            // Most likely a timeout or other general error
            $exception = 'Http::get failed:' . serialize( $error ) . serialize( $status );
            throw new TranslationWebServiceException( $exception );
        }
        $ret = $req->getContent();
        #wfErrorLog('获得了内容：'.$ret, '/tmp/wm.log' ); //调试信息
        
        $objResponse = json_decode($ret);
        if ($objResponse->error) { //抛出异常啥的
            throw new TranslationWebServiceException($objResponse->error_description); //抛给它去
        }
        #wfErrorLog("得到的最终token：".$objResponse->access_token, '/tmp/wm.log' ); //调试信息
        return $objResponse->access_token;
        
    }
    
    /*
     * 获得支持的翻译语言.
     *
     *
     * @返回 翻译配对.
     */
    protected function doPairs()
    {
        #wfErrorLog('开始配对信息', '/tmp/wm.log' ); //调试信息0
        //判断下数据是否齐全
        if (!isset($this->config['clientid']) || !isset($this->config['clientSecret'])) { //抛出异常啥的
            throw new TranslationWebServiceException('clientid or clientSecret is not set'); //抛出来后可能就死亡了
            //真奇怪呢，看起来出错就跳死了
        }
        
        /* 获得token开始 */
        $clientID     = $this->config['clientid']; //客户ID
        $clientSecret = $this->config['clientSecret']; //密匙

        $accessToken = $this->getMSTokens($clientID, $clientSecret); //获得token，简化这里
        /* 获得token结束 */
        
        $options            = array();
        $options['method']  = 'GET';
        $options['timeout'] = $this->config['timeout'];
            
        $url = 'http://api.microsofttranslator.com/V2/Http.svc/GetLanguagesForTranslate?';

        $req = MWHttpRequest::factory($url, $options);
        //设置头部验证
        $req->setHeader("Authorization", "Bearer " . $accessToken);
        
        wfProfileIn('TranslateWebServiceRequest-' . $this->service . '-pairs');
        $status = $req->execute();
        wfProfileOut('TranslateWebServiceRequest-' . $this->service . '-pairs');
        
        #wfErrorLog('验证号'.  $accessToken."\n", '/tmp/wm.log' ); //调试信息
        
        if (!$status->isOK()) {
                       
            $error = $req->getContent();

			#wfErrorLog('获取翻译语言支持死了咯 >'.$error, '/tmp/wm.log' ); //调试信息，粗略的

            // Most likely a timeout or other general error	
            throw new TranslationWebServiceException('Http::get failed:' . serialize($error) . serialize($status));
        }

        $xml = simplexml_load_string($req->getContent());
        
		#wfErrorLog('取回支持语言：'.$req->getContent(), '/tmp/wm.log' ); //调试信息，可以清理

        $languages = array();
        foreach ($xml->string as $language) {
            $languages[] = strval($language);
        }
        
        // Let's make a cartesian product, assuming we can translate from any language to any language
        // language to any language
        $pairs = array();
        foreach ($languages as $from) {
            foreach ($languages as $to) {
                #wfErrorLog('写入支持语言：'.$from.":".$to,"/tmp/wm.log");
                if ($from != $to){ #不给写入完全一样的语言代码
                    $pairs[$from][$to] = true;
                }
            }
        }
        
        return $pairs;
    }
    
    protected function doRequest($text, $from, $to)
    {
        /* 判断下数据是否齐全 */
        if (!isset($this->config['clientid']) || !isset($this->config['clientSecret'])) { //抛出异常啥的
            throw new TranslationWebServiceException('API key is not set'); //抛出来后可能就死亡了
            //真奇怪呢，看起来出错就跳死了
        }

        #wfErrorLog("准备翻译咯\n", '/tmp/wm.log' ); //调试信息

        /* 获得token开始 */
        $clientID     = $this->config['clientid']; //客户ID
        $clientSecret = $this->config['clientSecret']; //密匙
        
        $accessToken = $this->getMSTokens($clientID, $clientSecret); //获得token，简化这里
        /* 获得token结束 */
        
        /* 处理待翻译文本 */
        $text = trim($text);
        $text = $this->wrapUntranslatable($text); //大概是除掉一些标记，一些换行啥的换成!N!
        /* 待翻译文本处理完毕 */
        
        $options = array();
        $options['timeout'] = $this->config['timeout'];
        
        $params = array(
            'text' => $text,
            'from' => $from,
            'to' => $to
            //'appId' => $this->config['key'],
        );
        
        $url = 'http://api.microsofttranslator.com/V2/Http.svc/Translate?';
		//转录参数到地址
        $url .= wfArrayToCgi($params);
        
        #wfErrorLog("本次翻译地址:".$url."\n", '/tmp/wm.log' ); //调试信息
        
        $req = MWHttpRequest::factory($url, $options);
        //设置头部验证
        $req->setHeader("Authorization", "Bearer " . $accessToken);
        
        wfProfileIn('TranslateWebServiceRequest-' . $this->service);
        $status = $req->execute();
        wfProfileOut('TranslateWebServiceRequest-' . $this->service);
        
        if (!$status->isOK()) {
            #wfErrorLog('死了咯\n', '/tmp/wm.log' ); //调试信息
            
            $error = $req->getContent();
            // Most likely a timeout or other general error
            $exception = 'Http::get failed: ' . $url . serialize( $error ) . serialize( $status );
            throw new TranslationWebServiceException( $exception );
        }
        
        $ret  = $req->getContent();
		//滤除xml部分
        $text = preg_replace('~<string.*>(.*)</string>~', '\\1', $ret);
        $text = Sanitizer::decodeCharReferences($text); //解释乱码啥的，看起来就是注释里面的所有标记
        
        $text = str_replace('! N!', '!N!', $text); //替换返回来的换行符号乱码，被转为空格

		#wfErrorLog('返回字符串'.$text, '/tmp/wm.log' ); //调试信息

        return $this->unwrapUntranslatable($text);
    }
}
