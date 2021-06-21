<?php 

    /**
     * Written by Aylmer
     * Function :哔哩哔哩自动投币
     * Date/Time:2021/06/18
     * update 2021/06/20 : 修复了任务队列获取不完整的bug [ 函数handleMQ()内for循环条件 ]
     * update 2021/06/20 : 修复了自动投币过程中永远只给三个视频投币的bug [ 不能使用count()方法，因为该方法会重置for循环条件，应使用固定int值5 ]
     * update 2021/06/20 : 新增了失败队列机制 [ 将第一次处理失败的视频加入失败队列，最后取出失败队列里的视频再次进行投币尝试 ]
    */

    date_default_timezone_set('PRC');
    set_time_limit(0);

    /**
     * @desc 从本地txt获取账号token等配置数据
     * @param null
     * @return mixed array:raw数组|false:获取失败 
    */
    function getConfig(){
        try{
            $filename = "./config.txt";
            $handle = fopen($filename, "r");
            $temp = fread($handle, filesize($filename));
            $temp = str_replace('\r', '', $temp);
            $temp = str_replace('\n', '', $temp);
            $temp = str_replace(' ', '', $temp);
            fclose($handle);
            $temp = json_decode($temp, true);
            $content = explode('?', $temp['data']['url']);
            if(count($content) > 2){
                $_temp = "";
                for($i=1;$i<count($content);$i++){
                    $_temp .= $content[$i];
                }
                return $_temp;
            }elseif(count($content) == 2){
                return $content[1];
            }else{
                return false;
            }
            
        }
        catch(Exception $ex){
            return false;
        }
    }


    /**
     * @desc 提取url参数，转置为key:value格式数组
     * @param string $raw
     * @return mixed array:成功转置的数组|false:转置失败
    */
    function handleConfig(string $raw){
        $vars = explode("&", $raw);
        $result = array();
        for($i=0;$i<count($vars);$i++){
            // print($vars[$i] . "\n");
            $temp = explode('=', $vars[$i]);
            $result[$temp[0]] = $temp[1];
        }
        if(count($result) == 0){
            return false;
        }
        return $result;
    }


    /**
     * @desc 日志记录
     * @param string $msg
     * @return null
    */
    function logger(string $msg){
        print($msg . "\n");
        $filename = "./log.txt";
        $handle = fopen($filename, "a");
        fwrite($handle, date("Y-m-d H:i:s", time()) . '----' . $msg . "\n");
        fclose($handle);
        return;
    }


    /**
     * @desc 校验SESSDATA有效性，同时判断硬币数量是否足够
     * @param string $SESSDATA 
     * @param string $DedeUserID
     * @return bool true:硬币足够|false:硬币不足
    */
    function getCoinNum(string $SESSDATA, string $DedeUserID){
        $url = 'http://account.bilibili.com/site/getCoin';
        try{
            $ch =curl_init();
            $header = array();
            curl_setopt($ch,CURLOPT_URL,$url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
            curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
            curl_setopt($ch,CURLOPT_HEADER,false);
            curl_setopt($ch,CURLOPT_HTTPHEADER,$header);
            curl_setopt($ch,CURLOPT_COOKIE,'SESSDATA='. $SESSDATA .'; DedeUserID='. $DedeUserID .';');
            if (1 == strpos("$".$url, "https://")){
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            }
            $content = curl_exec($ch);
            curl_close($ch);
            if($content !== false){
                $result = json_decode($content, true);
                if($result['code'] == 0){
                    if($result['data']['money'] !== null && $result['data']['money'] >= 10){
                        logger('成功登录！硬币数量:' . $result['data']['money']);
                        return true;
                    }else{
                        logger('硬币数量不足。');
                        return false;
                    }
                }elseif($result['code'] == -101){
                    logger('账号登录失败，检查cookie是否过期。');
                    DingSend("在账号登录尝试时失败，请立即上线检查并更新cookie配置。");
                    return false;
                }else{
                    logger('账号登录失败，未知原因。');
                    return false;
                }
            }else{
                logger('网络请求失败。');
                return false;
            }
        }
        catch(Exception $ex){
            logger("获取失败，原因:" . $ex->getMessage());
            return false;
        }
    }


    /**
     * @desc 获取历史记录数组，第一次过滤
     * @param string $SESSDATA
     * @return mixed array:初步过滤后的历史记录|false:获取失败
    */
    function getHistory(string $SESSDATA){
        $url = "http://api.bilibili.com/x/web-interface/history/cursor?max=0&business=archive&view_at=0&ps=20";
        try{
            $ch =curl_init();
            $header = array();
            curl_setopt($ch,CURLOPT_URL,$url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
            curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
            curl_setopt($ch,CURLOPT_HEADER,false);
            curl_setopt($ch,CURLOPT_HTTPHEADER,$header);
            curl_setopt($ch,CURLOPT_COOKIE,'SESSDATA='. $SESSDATA .';');
            if (1 == strpos("$".$url, "https://")){
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            }
            $content = curl_exec($ch);
            if($content === false){
                curl_close($ch);
                return false;
            }
            $content = json_decode($content, true);
            if($content['code'] !== 0){
                logger($content['message']);
                return false;
            }
            $result = array();
            for($i=0;$i<count($content['data']['list']);$i++){            //  任务队列第一次过滤（条件：不是番剧、剧集、直播等）
                if($content['data']['list'][$i]['badge'] === ""){
                    array_push($result, $content['data']['list'][$i]['history']['bvid']);
                }else{
                    continue;
                }
            }
            //print_r($result);
            return $result;
        }
        catch(Exception $ex){
            logger("获取历史记录失败，原因:" . $ex->getMessage());
            return false;
        }
    }


    /**
     * @desc 判断视频是否原创视频(非原创视频投币不得经验)
     * @param string $bvid
     * @param string $SESSDATA
     * @return bool true:是原创视频|false:是转载视频
    */
    function getCopyright(string $bvid, string $SESSDATA){
        $url = "http://api.bilibili.com/x/web-interface/view?bvid=" . $bvid;
        try{
            $ch =curl_init();
            $header = array();
            curl_setopt($ch,CURLOPT_URL,$url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
            curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
            curl_setopt($ch,CURLOPT_HEADER,false);
            curl_setopt($ch,CURLOPT_HTTPHEADER,$header);
            curl_setopt($ch,CURLOPT_COOKIE,'SESSDATA='. $SESSDATA .';');
            if (1 == strpos("$".$url, "https://")){
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            }
            $content = curl_exec($ch);
            if($content === false){
                curl_close($ch);
                return false;
            }
            $content = json_decode($content, true);
            if($content['code'] !== 0){
                logger("视频(bvid:" . $bvid . ")，" . $content['message']);
                return false;
            }else{
                if($content['data']['copyright'] === 1){                  // 原创
                    return true;
                }elseif($content['data']['copyright'] === 2){             //转载
                    return false;
                }else{
                    return false;
                }
            }
        }
        catch(Exception $ex){
            logger("获取视频(bvid:" . $bvid . ")版权信息失败，原因:" . $ex->getMessage());
            return false;
        }
    }


    /**
     * @desc 判断视频是否已经投过币
     * @param string $bvid
     * @param string $SESSDATA
     * @return bool true:未投币|false:投过币了
    */
    function isCoined(string $bvid, string $SESSDATA){
        $url = "http://api.bilibili.com/x/web-interface/archive/coins?bvid=" . $bvid;
        try{
            $ch =curl_init();
            $header = array();
            curl_setopt($ch,CURLOPT_URL,$url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
            curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
            curl_setopt($ch,CURLOPT_HEADER,false);
            curl_setopt($ch,CURLOPT_HTTPHEADER,$header);
            curl_setopt($ch,CURLOPT_COOKIE,'SESSDATA='. $SESSDATA .';');
            if (1 == strpos("$".$url, "https://")){
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            }
            $content = curl_exec($ch);
            if($content === false){
                curl_close($ch);
                return false;
            }
            $content = json_decode($content, true);
            if($content['code'] !== 0){
                logger("视频(bvid:" . $bvid . ")，" . $content['message']);
                return false;
            }else{
                if($content['data']['multiply'] !== 0){                   //投过币，返回false
                    return false;
                }else{
                    return true;
                }
            }
        }
        catch(Exception $ex){
            logger("获取视频(bvid:" . $bvid . ")是否已投币失败，原因:" . $ex->getMessage());
            return false;
        }
    }


    /**
     * @desc 第二次过滤历史记录，只取5条记录，处理为最终的消息队列
     * @param array $queue
     * @param string $SESSDATA
     * @return array $result 处理成功的消息队列
    */
    function handleMQ(array $queue, string $SESSDATA){
        $result = array();
        for($i=0;$i<count($queue);$i++){                                  //任务队列第二次过滤（条件：属于原创视频，且还未投过币，投币可获得10经验）
            if(count($result) >= 5){
                return $result;
            }

            if(!getCopyright($queue[$i], $SESSDATA)){
                logger("视频（bvid:" . $queue[$i] . "）不符合条件[原创]");
                continue;
            }

            sleep(1);                                                     //休眠一秒秒钟，防止因访问频率过快被风控系统ban掉

            if(isCoined($queue[$i], $SESSDATA)){
                logger("视频（bvid:" . $queue[$i] . "）已入队列。");
                array_push($result, $queue[$i]);
            }else{
                logger("视频（bvid:" . $queue[$i] . "）不符合条件[未投币]");
                continue;
            }

            sleep(2);                                                     //休眠两秒秒钟，防止因访问频率过快被风控系统ban掉
        }
        return $result;
    }


    /**
     * @desc 给指定bv号的视频投一个硬币
     * @param string $bvid
     * @param string $SESSDATA
     * @param string $bili_jct (即csrf token)
     * @return bool true:投币成功|false:投币失败
    */
    function payCoin(string $bvid, string $SESSDATA, string $bili_jct){
        $url = "http://api.bilibili.com/x/web-interface/coin/add";
        $body = "bvid=" . $bvid . "&multiply=1&csrf=" . $bili_jct;        //multiply=1只投一个币
        try{
            $curl = curl_init();
            $header=array();
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "POST");
            curl_setopt($curl, CURLOPT_URL, $url);
            curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
            curl_setopt($curl, CURLOPT_FAILONERROR, false);
            curl_setopt($curl, CURLOPT_HEADER, false);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_COOKIE,'SESSDATA='. $SESSDATA .';');
            if (1 == strpos("$".$url, "https://")){
                curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
            }
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
            $content = curl_exec($curl);
            if($content !== false){
                $content = json_decode($content, true);
                if($content['code'] === 0){
                    logger("给视频(" . $bvid . ")投币成功。");
                    return true;
                }else{
                    logger("给视频(" . $bvid . ")投币失败#1！原因:" . $content['message']);
                    return false;
                }
            }else{
                return false;
            }
        }
        catch(Exception $ex){
            logger("给视频(bvid:" . $bvid . ")投币失败#2，原因:" . $ex->getMessage());
            return false;
        }
    }


    /**
     * 调用钉钉机器人API发送消息
     * @param string $msg
     * @return null
    */
    function DingSend(string $msg){
        $data = array(
            'msgtype' => 'text',
            'text' => array(
                'content' => 'bilibili投币Bot：' . $msg,
            ),
            'at' => array(
                'isAtAll' => true,
            )
        );
        $remote_server = "https://xxxxxxxxxxxxxxxxxxxxx";                 //此处填写钉钉机器人webapi地址
        $post_string = json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $ch = curl_init();  
        curl_setopt($ch, CURLOPT_URL, $remote_server);
        curl_setopt($ch, CURLOPT_POST, 1); 
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5); 
        curl_setopt($ch, CURLOPT_HTTPHEADER, array ('Content-Type: application/json;charset=utf-8'));
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_string);  
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);  
        curl_setopt ($ch, CURLOPT_SSL_VERIFYHOST, 0); 
        curl_setopt ($ch, CURLOPT_SSL_VERIFYPEER, 0);
        $result = curl_exec($ch);
        curl_close($ch); 
        return;
    }


    /*  -- Main Start --  */
    if(($raw = getConfig()) != false){
        if(($config_arr = handleConfig($raw))){
            if(($status = getCoinNum($config_arr['SESSDATA'], $config_arr['DedeUserID'])) === true){
                $mq = getHistory($config_arr['SESSDATA']);
                $mq = handleMQ($mq, $config_arr['SESSDATA']);
                $mq_fail = array();                                       //第一次投币失败的视频队列（后面会重新尝试）
                //print_r($mq);
                for($i=0;$i<5;$i++){                                      //遍历队列，开始投币【注意注意，这里的for循环条件不要用count()，因为每执行一次循环成功后都会unset当前元素】
                    if(payCoin($mq[$i], $config_arr['SESSDATA'], $config_arr['bili_jct'])){
                        unset($mq[$i]);
                    }else{
                        array_push($mq_fail, $mq[$i]);
                        continue;
                    }
                    sleep(2);                                             //休眠两秒秒钟，防止因访问频率过快被风控系统ban掉
                }
                $count = 5-count($mq);
                if($count == 5){
                    logger("任务完成，成功给" . (string)$count . "个视频投币");
                    DingSend("今日投币任务完成，成功给" . (string)$count . "个视频投币。");
                }else{
                    logger("开始处理失败队列，个数：" . (string)count($mq_fail));
                    for($i=0;$i<count($mq_fail);$i++){
                        payCoin($mq_fail[$i], $config_arr['SESSDATA'], $config_arr['bili_jct']);
                        sleep(2);
                    }
                    logger("任务结束，成功处理个数：" . (string)$count . "，处理失败个数：" . (string)(5 - $count) . "，再次尝试后剩余失败视频数：" . (string)(count($mq_fail)));
                }
                
                exit();
            }else{
                exit();
            }
        }else{
            logger('handleConfig()错误。');
            exit();
        }
    }else{
        logger('getConfig()错误。');
        exit();
    }
    /*  -- Main End --  */
?>