<?php

namespace Gini\ORM\ChemicalLimits;

class Request extends \Gini\ORM\Mall\RObject
{
    // 化学品分类
    public $type = 'string:20';
    // 化学品cas号
    public $cas_no = 'string:40';
    // 化学品名称
    public $name = 'string:150';
    // 课题组
    public $group  = 'object:group';
    // 申请上限
    public $volume = 'string:120';
    // 当前状态
    public $status = 'int,default:0';
    // 创建时间
    public $ctime = 'datetime';
    public $owner = 'object:user';
    // 拒绝时间
    public $reject_time = 'datetime';
    // 拒绝人
    public $reject_man = 'object:user';
    // 通过时间
    public $pass_time = 'datetime';
    // 通过人
    public $pass_man = 'object:user';
    // 修改时间
    public $mtime = 'datetime';

    protected static $db_index = [
        'group',
        'ctime',
        'mtime',
    ];

    // 待审核
    const STATUS_PENDING = 0;
    // 审核通过
    const STATUS_PASSED = 1;
    // 审核失败
    const STATUS_REJECTED = 2;

}

