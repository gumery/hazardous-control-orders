<?php

namespace Gini\Controller\CLI\HazardousControl;

class RelationshipOP extends \Gini\Controller\CLI
{
    public function __index($args)
    {
        echo "Available commands:\n";
        echo "\tgini hazardouscontrol relationshipop prepare-table\n";
        echo "\tgini hazardouscontrol relationshipop build\n";
    }

    private static function _getTableName()
    {
        return \Gini\Config::get('hazardous-control-orders.table') ?: '_hazardous_control_order_product';
    }

    private static $_tagRPC;
    private static function _getTagRPC()
    {
        if (self::$_tagRPC) return self::$_tagRPC;

        $conf = \Gini\Config::get('tag-db.rpc');
        $tagURL = $conf['url'];
        $client = \Gini\Config::get('tag-db.client');
        $clientID= $client['id'];
        $clientSecret = $client['secret'];
        $rpc = \Gini\IoC::construct('\Gini\RPC', $tagURL);
        if ($rpc->tagdb->authorize($clientID, $clientSecret)) {
            self::$_tagRPC = $rpc;
        }

        return self::$_tagRPC;
    }

    private static function _getTagData($gid = 0)
    {
        $node = \Gini\Config::get('app.node');
        $tag = "labmai-{$node}/{$gid}";
        $rpc = self::_getTagRPC();
        return (array)$rpc->tagdb->data->get($tag);
    }

    private static $schema = [
        'fields' => [
            'id' => [
                'type' => 'bigint',
                'serial' => 1,
            ],
            'order_id' => [
                'type' => 'bigint',
            ],
            'order_ctime' => [
                'type' => 'datetime',
            ],
            'order_mtime' => [
                'type' => 'datetime',
            ],
            'order_md5' => [
                'type' => 'varchar(32)',
            ],
            'product_id' => [
                'type' => 'bigint',
            ],
            'chem_reagent' => [
                'type' => 'bigint',
            ],
            'bio_reagent' => [
                'type' => 'bigint',
            ],
            'consumable' => [
                'type' => 'bigint',
            ],
            'hazardous' => [
                'type' => 'bigint',
            ],
            'drug_precursor' => [
                'type' => 'bigint',
            ],
            'highly_toxic' => [
                'type' => 'bigint',
            ],
            'explosive' => [
                'type' => 'bigint',
            ],
            'cas_no' => [
                'type' => 'varchar(15)',
                'null' => true,
            ],
            'vendor_id' => [
                'type' => 'bigint',
            ],
            'vendor_name' => [
                'type' => 'varchar(120)',
            ],
            'group_id' => [
                'type' => 'bigint',
            ],
            'group_name' => [
                'type' => 'varchar(120)',
            ],
            'college_code' => [
                'type' => 'varchar(120)',
            ],
            'college_name' => [
                'type' => 'varchar(120)',
            ],
            'department_code' => [
                'type' => 'varchar(120)',
            ],
            'department_name' => [
                'type' => 'varchar(120)',
            ],
            'order_price' => [
                'type' => 'double',
            ],
            'product_package' => [
                'type' => 'varchar(50)',
            ],
            'product_quantity' => [
                'type' => 'double',
            ],
            'product_unit_price' => [
                'type' => 'double',
            ],
            'product_total_price' => [
                'type' => 'double',
            ],
            'order_status' => [
                'type' => 'int',
            ],
            'product_name' => [
                'type' => 'varchar(255)',
            ],
        ],
        'indexes' => [
            'PRIMARY' => [
                'type' => 'primary',
                'fields' => ['id'],
            ],
            '_MIDX_CHEM_REAGENT' => [
                'fields' => ['chem_reagent'],
            ],
            '_MIDX_BIO_REAGENT' => [
                'fields' => ['bio_reagent'],
            ],
            '_MIDX_CONSUMABLE' => [
                'fields' => ['consumable'],
            ],
            '_MIDX_HAZARDOUS' => [
                'fields' => ['hazardous'],
            ],
            '_MIDX_DRUG_PRECURSOR' => [
                'fields' => ['drug_precursor'],
            ],
            '_MIDX_HIGHLY_TOXIC' => [
                'fields' => ['highly_toxic'],
            ],
            '_MIDX_EXPLOSIVE' => [
                'fields' => ['explosive'],
            ],
            '_MIDX_VENDOR_NAME' => [
                'fields' => ['vendor_name'],
            ],
            '_MIDX_VENDOR_ID' => [
                'fields' => ['vendor_id'],
            ],
            '_MIDX_GROUP_ID' => [
                'fields' => ['group_id'],
            ],
            '_MIDX_GROUP_NAME' => [
                'fields' => ['group_name'],
            ],
            '_MIDX_COLLEGE_CODE' => [
                'fields' => ['college_code'],
            ],
            '_MIDX_COLLEGE_NAME' => [
                'fields' => ['college_name'],
            ],
            '_MIDX_DEPARTMENT_CODE' => [
                'fields' => ['department_code'],
            ],
            '_MIDX_DEPARTMENT_NAME' => [
                'fields' => ['department_name'],
            ],
            '_MIDX_ORDER_ID' => [
                'fields' => ['order_id'],
            ],
            '_MIDX_ORDER_MD5' => [
                'fields' => ['order_md5'],
            ],
            '_MIDX_ORDER_CTIME' => [
                'fields' => ['order_ctime'],
            ],
            '_MIDX_ORDER_MTIME' => [
                'fields' => ['order_mtime'],
            ],
            '_MIDX_PRODUCT_NAME' => [
                'fields' => ['product_name'],
            ],
            '_MIDX_PRODUCT_CAS' => [
                'fields' => ['cas_no'],
            ],
        ],
    ];

    public function actionPrepareTable()
    {
        $tableName = self::_getTableName();
        $schema = self::$schema;
        $db = \Gini\Database::db();
        $db->adjustTable($tableName, $schema);
        if ($db->query("DESC {$tableName}")) {
            echo "Prepare Table {$tableName}: Done\n";
        } else {
            echo "Prepare Table {$tableName}: Failed\n";
        }
    }

    private function getMd5($row)
    {
        return md5(J($row->items).$row->status);
    }

    public function actionBuild()
    {
        $pidFile = APP_PATH.'/'.DATA_DIR.'/hazardous-control-orders-build.pid';
        if (file_exists($pidFile)) {
            $rawPID = (int) file_get_contents($pidFile);
            // 如果进程已经在执行 直接退出
            if ($rawPID && $this->filterWorkers($rawPID)) {
                return;
            }
        }
        $pid = getmypid();
        file_put_contents($pidFile, $pid);

        $db = \Gini\Database::db();
        $tableName = self::_getTableName();
        $max = $db->query("SELECT max(order_mtime) FROM {$tableName}")->value() ?: 0;
        $start = 0;
        $perpage = 100;
        $schema = self::$schema;
        $keys = array_keys($schema['fields']);
        array_shift($keys);
        $keys = implode(',', $keys);
        while (true) {
            $rows = those('order')->whose('mtime')->isGreaterThan($max)->andWhose('customized')->is(0)->orderBy('mtime', 'asc')->limit($start, $perpage);
            if (!count($rows)) {
                break;
            }
            $start += $perpage;

            $values = [];
            foreach ($rows as $row) {
                $items = (array) $row->items;
                $orgs = $this->_getTagData($row->group->id);
                $orgs = $orgs['organization'];
                $college_code = $orgs['parent']['code'] ?: $orgs['code'];
                $college_name = $orgs['parent']['name'] ?: $orgs['name'];
                $department_code = $orgs['parent']['code'] ? $orgs['code'] : '';
                $department_name = $orgs['parent']['name'] ? $orgs['name'] : '';
                $qRowID = $db->quote($row->id);
                $qRowMd5 = $db->quote($this->getMd5($row));
                // 检测有没有历史数据
                $hisCount = $db->query("SELECT COUNT(*) FROM {$tableName} WHERE order_id={$qRowID}")->value() ?: 0;
                if ($hisCount) {
                    // 检测历史数据是不是已经跟最新的数据不一致了
                    // 如果不一致，需要删除重建
                    // 如果一致，就不需要在重复添加了
                    $query = $db->query("DELETE FROM {$tableName} WHERE order_id={$qRowID} AND order_md5!={$qRowMd5}");
                    if (!$query || !$query->count()) {
                        echo "\norder#{$row->id} 更新数据失败\n";
                        continue;
                    }
                }

                foreach ($items as $i => $item) {
                    // 如果商品价格是待询价, 当成无效订单处理
                    if ($item['unit_price'] < 0) {
                        continue;
                    }
                    $product = a('product', $item['id']);
                    // 商品会不会不存在？
                    if (!$product->id) {
                        continue;
                    }
                    $myTypes = $this->_getProductTypes($product->type, $product->rgt_type, $product->cas_no);
                    if (empty($myTypes)) {
                        continue;
                    }
                    $values[] = '('.implode(',', [
                        // orderid
                        $db->quote($row->id),
                        // orderctime
                        $db->quote($row->ctime),
                        // ordermtime
                        $db->quote($row->mtime),
                        // ordermd5
                        $db->quote($this->getMd5($row)),
                        // productid
                        $db->quote($product->id),
                        (int)in_array('chem_reagent', $myTypes),
                        (int)in_array('bio_reagent', $myTypes),
                        (int)in_array('consumable', $myTypes),
                        (int)in_array('hazardous', $myTypes),
                        (int)in_array('drug_precursor', $myTypes),
                        (int)in_array('highly_toxic', $myTypes),
                        (int)in_array('explosive', $myTypes),
                        // casno
                        $db->quote(trim($product->cas_no)),
                        // vendorid
                        $db->quote($row->vendor->id),
                        // vendorname
                        $db->quote($row->vendor->name),
                        // groupid
                        $db->quote($row->group->id),
                        // groupname
                        $db->quote($row->group->title),
                        // college_code
                        $db->quote($college_code),
                        // college_name
                        $db->quote($college_name),
                        // dep_code
                        $db->quote($department_code),
                        // dep_name
                        $db->quote($department_name),
                        // orderprice
                        $db->quote(round($row->price, 2)),
                        // product package
                        $db->quote(trim($product->package)),
                        // product quantity
                        $db->quote($item['quantity']),
                        // product unit price
                        $db->quote($item['unit_price']),
                        // product total price
                        $db->quote($item['price']),
                        // order status
                        $db->quote($row->status),
                        // product name
                        $db->quote($this->_getProductName($item['name'], $product->cas_no, $myType)),
                    ]).')';
                }
            }
            if (empty($values)) {
                continue;
            }
            $values = implode(',', $values);
            $db = \Gini\Database::db();
            $sql = "INSERT INTO {$tableName}({$keys}) VALUES {$values};";
            if ($db->query($sql)) {
                echo '.';
            } else {
                echo 'x';
            }
        }
    }

    private static $data = [];
    private function _getProductName($name, $cas = null, $type = null)
    {
        if (!$cas) {
            return $name;
        }
        if (isset(self::$data[$cas])) {
            $data = self::$data[$cas];
        } else {
            self::$data[$cas] = $data = (array) \Gini\ChemDB\Client::getProduct($cas);
        }
        if (empty($data)) {
            return $name;
        }
        if ($type) {
            foreach ($data as $d) {
                if ($d['type'] == $type) {
                    return $d['name'];
                }
            }
        }

        return $name;
    }

    public function actionInit()
    {
        $db = \Gini\Database::db();
        $tableName = self::_getTableName();
        $max = $db->query("SELECT max(order_mtime) FROM {$tableName}")->value() ?: 0;
        $start = 0;
        $perpage = 100;
        $schema = self::$schema;
        $keys = array_keys($schema['fields']);
        array_shift($keys);
        $keys = implode(',', $keys);
        while (true) {
            $rows = those('order')->whose('mtime')->isGreaterThan($max)->andWhose('customized')->is(0)->orderBy('mtime', 'asc')->limit($start, $perpage);
            if (!count($rows)) {
                break;
            }
            $start += $perpage;

            $values = [];
            foreach ($rows as $row) {
                $items = (array) $row->items;

                $qRowID = $db->quote($row->id);
                $qRowMd5 = $db->quote($this->getMd5($row));
                // 检测有没有历史数据
                $hisCount = $db->query("SELECT COUNT(*) FROM {$tableName} WHERE order_id={$qRowID}")->value() ?: 0;
                if ($hisCount) {
                    // 检测历史数据是不是已经跟最新的数据不一致了
                    // 如果不一致，需要删除重建
                    // 如果一致，就不需要在重复添加了
                    $query = $db->query("DELETE FROM {$tableName} WHERE order_id={$qRowID} AND order_md5!={$qRowMd5}");
                    if (!$query || !$query->count()) {
                        echo "\norder#{$row->id} 更新数据失败\n";
                        continue;
                    }
                }

                foreach ($items as $i => $item) {
                    // 如果商品价格是待询价, 当成无效订单处理
                    if ($item['unit_price'] < 0) {
                        continue;
                    }
                    $values[] = '('.implode(',', [
                        // orderid
                        $db->quote($row->id),
                        // orderctime
                        $db->quote($row->mtime),
                        // ordermd5
                        $db->quote($this->getMd5($row)),
                        // productid
                        $db->quote($item['id']),
                        // producttype
                        $db->quote(''),
                        // casno
                        $db->quote(''),
                        // vendorid
                        $db->quote($row->vendor->id),
                        // vendorname
                        $db->quote($row->vendor->name),
                        // groupid
                        $db->quote($row->group->id),
                        // groupname
                        $db->quote($row->group->title),
                        // orderprice
                        $db->quote(round($row->price, 2)),
                        // product package
                        $db->quote(''),
                        // product quantity
                        $db->quote($item['quantity']),
                        // product unit price
                        $db->quote($item['unit_price']),
                        // product total price
                        $db->quote($item['price']),
                        // order status
                        $db->quote($row->status),
                        // product name
                        $db->quote(''),
                    ]).')';
                }
            }
            if (empty($values)) {
                continue;
            }
            $values = implode(',', $values);
            $db = \Gini\Database::db();
            $sql = "INSERT INTO {$tableName}({$keys}) VALUES {$values};";
            if ($db->query($sql)) {
                echo '.';
            } else {
                echo 'x';
            }
        }
    }

    /*
    // 通过actionInit初始化第一版的数据，并将数据导入到hub_product的数据库，并在hub——product上执行actionRun这个方法
    // 根据商品id完善商品信息
    // 主要是因为rpc请求过多，build脚本执行超时频繁，所以，第一次商品信息的补充不用rpc的方式
    public function actionRun()
    {
        $tableName = '_hazardous_control_order_product';
        $start = 0;
        $perpage = 10;
        $db = \Gini\Database::db();

        while (true) {
            $sql = "select id,product_id from {$tableName} order by id desc limit {$start}, {$perpage}";
            $rows = $db->query($sql)->rows();
            if (!count($rows)) break;
            $start += $perpage;
            foreach ($rows as $row) {
                $tmpID = $row->product_id;
                $product = a('product', $tmpID);
                $tmpType = $product->type;
                if ($product->type=='chem_reagent') {
                    $tmpType = [
                        1=> 'chem_reagent',
                        2=> 'hazardous',
                        3=> 'drug_precursor',
                    ][$product->rgt_type];
                }
                $tmpSQL = strtr("UPDATE _hazardous_control_order_product SET product_type=:ptype,cas_no=:cn,product_package=:package, product_name=:pname WHERE id={$row->id}", [
                    ':ptype'=> $db->quote($tmpType),
                    ':cn'=> $db->quote(trim($product->cas_no)),
                    ':package'=> $db->quote($product->package),
                    ':pname'=> $db->quote($product->name)
                ]);
                $bool = $db->query($tmpSQL);
                if ($bool) {
                    echo '.';
                }
                else {
                    echo 'x';
                }
                //echo "{$row->id}: {$row->product_id} / {$row->product_type}\n";
            }
        }
    }
     */

    private function _getProductTypes($type, $subType = null, $cas = null)
    {
        $result = [];
        if ($cas) {
            if (isset(self::$data[$cas])) {
                $data = self::$data[$cas];
            } else {
                self::$data[$cas] = $data = \Gini\ChemDB\Client::getProduct($cas);
            }
            if (is_array($data)) {
                foreach ($data as $d) {
                    $result[] = $d['type'];
                }
                $result[] = $type;
            }
            if (!empty($result)) {
                return $result;
            }
        }
        $types = [
            \Gini\ORM\Product::RGT_TYPE_NORMAL => 'normal',
            \Gini\ORM\Product::RGT_TYPE_HAZARDOUS => 'hazardous',
            \Gini\ORM\Product::RGT_TYPE_DRUG_PRECURSOR => 'drug_precursor',
            \Gini\ORM\Product::RGT_TYPE_HIGHLY_TOXIC => 'highly_toxic',
            \Gini\ORM\Product::RGT_TYPE_EXPLOSIVE => 'explosive',
        ];
        if ($subType && isset($types[$subType])) {
            $result[] = $types[$subType];
        }
        $result[] = $type;

        return $result;
    }

    private function filterWorkers($pid)
    {
        return file_exists("/proc/{$pid}");
        /*
        $ps = shell_exec("ps -p {$pid}");
        return count(explode("\n", $ps)) > 2;
         */
    }
    /*
    public function actionResetPType()
    {
        $dba = \Gini\Database::db('chemdb');
        $dbb = \Gini\Database::db();
        $tableName = '_hazardous_control_order_product';
        $start = 0;
        $perpage = 25;
        $schema = self::$schema;
        $keys = array_keys($schema['fields']);
        array_shift($keys);
        $keys = implode(',', $keys);
        while (true) {
            $sql = "SELECT cas_no,name,GROUP_CONCAT(distinct type) as ptype FROM product GROUP BY cas_no LIMIT {$start}, {$perpage}";
            $rows = $dba->query($sql)->rows();
            if (!count($rows)) {
                break;
            }
            $start += $perpage;
            foreach ($rows as $row) {
                $type = $row->ptype;
                $types = explode(',', $type);
                $cas = $row->cas_no;
                if (count($types) > 1) {
                    $iSQL = strtr("SELECT * FROM {$tableName} where cas_no=:cno", [
                    ':cno' => $dbb->quote($row->cas_no),
                ]);
                    $tmpRows = $dbb->query($iSQL)->rows;
                    if (!$tmpRows || !count($tmpRows)) {
                        continue;
                    }
                    foreach ($tmpRows as $tmpRow) {
                        $tmpValues = [];
                        foreach ($types as $type) {
                            $tmpValues[] = '('.implode(',', [
                            // orderid
                            $dbb->quote($tmpRow->order_id),
                            // orderctime
                            $dbb->quote($tmpRow->order_ctime),
                            // ordermtime
                            $dbb->quote($tmpRow->order_mtime),
                            // ordermd5
                            $dbb->quote($tmpRow->order_md5),
                            // productid
                            $dbb->quote($tmpRow->product_id),
                            // producttype
                            $dbb->quote($type),
                            // casno
                            $dbb->quote($tmpRow->cas_no),
                            // vendorid
                            $dbb->quote($tmpRow->vendor_id),
                            // vendorname
                            $dbb->quote($tmpRow->vendor_name),
                            // groupid
                            $dbb->quote($tmpRow->group_id),
                            // groupname
                            $dbb->quote($tmpRow->group_title),
                            // college_code
                            $dbb->quote($tmpRow->college_code),
                            // college_name
                            $dbb->quote($tmpRow->college_name),
                            // department_code
                            $dbb->quote($tmpRow->department_code),
                            // department_name
                            $dbb->quote($tmpRow->department_name),
                            // orderprice
                            $dbb->quote(round($tmpRow->order_price, 2)),
                            // product package
                            $dbb->quote($tmpRow->product_package),
                            // product quantity
                            $dbb->quote($tmpRow->product_quantity),
                            // product unit price
                            $dbb->quote($tmpRow->product_unit_price),
                            // product total price
                            $dbb->quote($tmpRow->product_total_price),
                            // order status
                            $dbb->quote($tmpRow->status),
                            // product name
                            $dbb->quote($tmpRow->product_name),
                        ]).')';
                        }
                        $tmpValues = implode(',', $tmpValues);
                        $tmpSQL = "INSERT INTO {$tableName}({$keys}) VALUES {$tmpValues};";
                        echo "\n\t{$tmpSQL}\n";
                        $dbb->query($tmpSQL);
                        $tmpSQL = "DELETE FROM {$tableName} WHERE id={$tmpRow->id}";
                        echo "\n\t{$tmpSQL}\n";
                        $dbb->query($tmpSQL);
                        echo '.';
                    }
                } else {
                    $iSQL = strtr("UPDATE {$tableName} SET product_name=:pname,product_type=:ptype WHERE cas_no=:cno", [
                    ':pname' => $dbb->quote($row->name),
                    ':ptype' => $dbb->quote($type),
                    ':cno' => $dbb->quote($row->cas_no),
                ]);
                    if ($dbb->query($iSQL)) {
                        echo '.';
                    } else {
                        echo 'x';
                    }
                }
            }
        }
    }
    */
}
