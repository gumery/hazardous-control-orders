<?php

namespace Gini\ORM\ChemicalLimits;

class Request extends \Gini\ORM\Mall\RObject
{
    // 化学品分类
    public $type = 'string:20';
    // 化学品cas号
    public $cas_no = 'string:120';
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
    public $approve_time = 'datetime';
    // 通过人
    public $approve_man = 'object:user';
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
    const STATUS_APPROVED = 1;
    // 审核失败
    const STATUS_REJECTED = 2;

    public static function getStatusTitle($status)
    {
        $titles = [
            self::STATUS_PENDING=> T('待审核'),
            self::STATUS_APPROVED=> T('已通过'),
            self::STATUS_REJECTED=> T('已拒绝'),
        ];
        return $titles[$status];
    }

    const CAS_DEFAULT_ALL   = 'all';
    const CAS_DEFAULT_HAZ   = 'hazardous';
    const CAS_DEFAULT_DRUG  = 'drug_precursor';
    const CAS_DEFAULT_TOXIC = 'highly_toxic';
    const CAS_DEFAULT_EXP   = 'explosive';
    const CAS_DEFAULT_PSY = 'psychotropic';
    const CAS_DEFAULT_NAR = 'narcotic';

    public static function getTypeTtile($type) {
        $titles = [
            self::CAS_DEFAULT_ALL   => T('全部'),
            self::CAS_DEFAULT_HAZ   => T('危化品'),
            self::CAS_DEFAULT_DRUG  => T('易制毒'),
            self::CAS_DEFAULT_TOXIC => T('剧毒品'),
            self::CAS_DEFAULT_EXP   => T('易制爆'),
            self::CAS_DEFAULT_PSY => T('精神药品'),
            self::CAS_DEFAULT_NAR => T('麻醉药品'),
        ];
        return $titles[$type];
    }
}

