<?php
namespace Novel\NovelSpider\Controller;

use Novel\NovelSpider\Models\ContentModel;
use QL\QueryList;
use Novel\NovelSpider\Models\ListModel;
use Libs\Helper\NumberTransfer;
use Novel\NovelSpider\Models\NovelListModel;

/**
 * Class Test
 * @package Novel\NovelSpider\Controller
 */
class Test{
    // 只针对 "大宋王候" 的Novel
    protected $baseUrl = 'http://www.biquwu.cc/biquge/17_17308/';
    protected $redisObj = null;
    protected $listUrlKey = 'novel-list-key';

    public function __construct(){
        if(!$this->redisObj){
            $backupParam = [
                'host'     => '127.0.0.1',
                'port'     => 6379,
                'database' => 0,
            ];
            $redis = new \Predis\Client();
            $this->redisObj = $redis;
            if(!$this->redisObj){
                echo 'redis没有启动吧!'.PHP_EOL;
                return false;
            }
        }
    }
    // 获取列表
    public function getList(){
        $url = 'http://www.biquwu.cc/biquge/17_17308/';
        //$url = 'http://www.zreading.cn/';
        $hj = QueryList::Query($url,
                                array(
                                    "latest"=>array('li:last','html'),
                                    "list"=>array('li','html'),
                                ),
                            '.article_texttitleb','utf-8');
        $data = $hj->getData(function($item){
            $item['list'] = QueryList::Query($item['list'],array(
                'link'=>array('a', 'href','',function($str){
                            return $this->baseUrl.$str;
                        }),
                'title'=>array('a', 'text'),
            ))->data;
            return $item;
        });

        return $data[0]['list'];
    }// end of function

    /**
     * 从redis获取列表
     * @param string $cacheKey redis的key
     * @return array|bool 返回数据列表
     */
    public function getListFromRedis($cacheKey=''){
        if(!$cacheKey)return false;
        $redis = $this->redisObj;
        //$redis -> hmset ( $keyConfig['list-key'] , $list ) ;
        //$redis -> del ( $keyConfig['list-key'] ) ;
        $res = $redis->hgetall($cacheKey);
        return $res;
    }

    /**
     * 通过一个url抓取详情
     * @param array $taskData
     * @return bool
     */
    public function getDetail($taskData,$type=2){
        if(!$taskData){
            echo "没有url可以抓取详情啦~1".PHP_EOL;
            return false;
        }
        $url = $taskData['url'];
        //$url = 'http://www.biquwu.cc/biquge/17_17308/c5056844.html';// test data
        $hj = QueryList::Query($url,
            array(
                "title"=>array('.bookname>h1','html'),
                "content"=>array('#content','html'),
            ),
            '#wrapper','UTF-8');
        $data = $hj->getData(function($item){
            return $item;
        });
        $data[0]['chapter'] = $taskData['chapter'];

        return $data[0];
    }
    /**
     * 从另一个worker进程中获取taskData
     */
    public function requestTaskDataFromProcess($taskConnection,$data = ['count'=>0,]){
        // 任务及参数数据
        $task_data = array(
            'function' => 'send_mail',
            'args'       => array('from'=>'detail task', 'to'=>'list task', 'contents'=>'get-detail'),//refresh-list
        );
        $task_data = $data ? $data : $task_data;
        //var_dump($data);

        $taskConnection->send(json_encode($task_data));
        // 执行异步链接 !!! important
        $taskConnection->connect();
        return true;
    }

    /**
     * 查询MySQL中的列表url,向redis中push  使用list数据结构
     */
    public function getListFromMysql($type=0){
        if(!$type)return false;
        $listModel = new NovelListModel();
        $res = $listModel->getList([
            'novel_id' => 2,
            'flag'     => 0,
            'limit'      => 1000,
        ]);

        return $res;
    }
    /**
     * 向redis中lpush数据url
     */
    public function pushIntoRedis($data){
        if(!$data)return false;
        $redis = $this->redisObj;
        $flag = '';
        // 如果有数据,则不用push
        if($redis->llen($this->listUrlKey)){
            $flag = $redis->llen($this->listUrlKey);
            return ['status'=>1, 'message'=>'redis队列中已经有数据，无需入队！'];
        }
        $dataIdArr = [];
        // 没有则 将数据push到redis中
        foreach($data as $k=>$v){
            $redis->lpush($this->listUrlKey,json_encode($v));
            $dataIdArr[] = $v['id'];
        }
        $flag = $redis->llen($this->listUrlKey);
        // push完成之后,将list表中的flag置为1
        $listModel = new NovelListModel();
        $updateResult = $listModel->whereIn('id', $dataIdArr)->update([
            'flag'=>1,
        ]);
        if (!$updateResult) {
            return ['status'=>4, 'message'=>'flag标记更新失败！'];
        }

        return ['status'=>1, 'message'=>'flag标记更新成功！'];
    }
    /**
     * 获取下一个可以抓取详情的url
     */
    public function getNextTaskData($type=2){
        $redis = $this->redisObj;
        $taskData = $redis->lpop($this->listUrlKey);
        if(!$taskData){
            $res = $this->getListFromMysql($type);
            if(!$res){
                echo "Mysql中也没有尚未抓取的url啦~1".PHP_EOL;
                return false;
            }
            $this->pushIntoRedis($res);
            $taskData = $redis->lpop($this->listUrlKey);
        }
        return $taskData;
    }
    /**
     * 存储1篇详情
     */
    public function saveDetail($dataOri = []){
        if(!$dataOri)return false;
        $data = [
            ''
        ];
    }// end of function
    /**
     * 获取历史抓取的最新的一章
     */
    public function getLatestChapter(){
        $listModel = new ListModel();
        $res = $listModel->getAll([
            'order'=>1,
            'num'=>1,
        ]);
        $res = $res[0];
        $ourNewestChapter = $res['chapter'];
        $res = $this->checkHasCrawling($res['chapter']);
    }
    /**
     * 检查这个url/id是否被爬取过
     */
    public function checkHasCrawling($chapter){
        $listModel = new ContentModel();
        $res = $listModel->getAll([
            'chapter'=>$chapter,
            'order'=>1,
            'num'=>1,
        ]);
        return $res ? true : false;
    }

    /**
     * 保存列表页到mysql
     */
    public function saveList(){
        $list = $this->getList();
        $listModel = new ListModel();
        $i = 0;
        foreach($list as $k=>$v){
            $data = [
                'novel_id'=>2,// 2 大宋王侯
                'url'=>$v['link'],
                'title'=>$v['title'],
                'chapter'=>NumberTransfer::checkNatInt($v['title']),
                'flag'=>0,
            ];
            $flag = $listModel->insertData($data);
        }
        return $flag;
    }




}// end of class


