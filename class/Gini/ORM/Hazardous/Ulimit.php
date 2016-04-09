<?php

/**
* @file Ulimit.php
* @brief 危化品购买上限, 为订单购买提供的扩展功能
* @author PiHiZi <pihizi@msn.com>
* @version 0.1.0
* @date 2016-04-09
 */
namespace Gini\ORM\Hazardous;

class Ulimit extends \Gini\ORM\Object
{
    public $cas_no = 'string:120';
    public $volume = 'string:120';

    protected static $db_index = [
        'unique:cas_no',
    ];

    public function save() 
    {
        $bool = \ChemicalReagent\CASNO::verify($this->cas_no);
        if (!$boo) return false;
        return parent::save();
    }
}
