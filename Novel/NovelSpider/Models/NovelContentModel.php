<?php
namespace Novel\NovelSpider\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;
use Illuminate\Database\Capsule\Manager as Capsule;

class NovelContentModel extends Eloquent
{
    protected $table = 'novel_content';

    // 禁用 Laravel 的时间戳字段数据属性
    public $timestamps = false;

    protected $fillable = [
        'id',
        'novel_id',
        'list_id',
        'chapter',
        'title',
        'content',
        'date',
        'add_time',
        'update_time',
        'err_flag',
        'delete_flag',
    ];

    /**
     * 内容的新增或更新
     * @param array $paramArr
     * @return array
     */
    public function detailInsertOrUpdate($paramArr = [])
    {
        $options = [
            'where' => [],//如果是新增，则where值可以为空；如果是更新，则where值为数组，例如 [ ['id','=','21']  ]
            'data'  => [],
        ];
        $options = array_merge($options, (array)$paramArr);
        $model = $this;
        if (!empty($options['where'])) {
            foreach ($options['where'] as $option) {
                $model = $model->where($option[0], $option[1], $option[2]);
            }
            $existObj = $model->get()->first();
            if (!is_null($existObj)) {
                foreach ($options['where'] as $option) {
                    $model = $model->where($option[0], $option[1], $option[2]);
                }
                $result  = $model->update($options['data']);
                $message = '更新';
            } else {
                $result  = $model->create($options['data']);
                $message = '新增';
            }
        } else {
            $result  = $model->create($options['data']);
            $message = '新增';
        }

        if (empty($result)) {
            return ['status' => 2, 'message' => $message . '失败！', 'data' => $result,];
        } else {
            return ['status' => 1, 'message' => $message . '成功！', 'data' => $result,];
        }
    }
}
