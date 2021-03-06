<?php
namespace app\models;

use Yii;
use yii\base\Model;
use app\models\Goods;
use app\models\GoodsCategories;
use app\models\Log;

/**
 * Goods manager model
 *
 */
class GoodsManager extends Model
{
    
    public $rootCategories = [];
    public $rootGoods = [];
    static protected $debug = ['db' => ''];


    public static function processAjaxRequest($rawData)
    {
        $decodedData = json_decode($rawData, true);
        $action = $decodedData['action'].'Action';        
        return json_encode(self::$action($decodedData['data']));
    }

    public static function createAction($data)
    {
        $name = $data['name'];
        $parentCategory = $data['cat'];
        if (empty($parentCategory) || !is_numeric($parentCategory) || $parentCategory == 0) {
            $parentCategory = 1;
        }
        $type = $data['type'];
        $error = '';
        $message = '';
        $item = Goods::find()->select('id,name')->where(['parent' => $parentCategory, 'name' => $name])->one();
        $category = GoodsCategories::find()->select('id,name')->where(['parent' => $parentCategory, 'name' => $name])->one();
        if (!empty($item) || !empty($category)) {
            $error = "Error: Name already used by another entry.";
        } elseif (!empty($name)) {
            if ($type == 'cat') {
                $category = new GoodsCategories();
                $category->name = $name;
                $category->parent = $parentCategory;
                if (!self::saveModelUsingTransaction($category)) {
                    $error = 'Error: DB write error.';
                }
                $message = 'Category ' . $name . ' created';
            } elseif ($type == 'item') {
                $item = new Goods();
                $item->name = $name;
                $item->parent = $parentCategory;
                if (!self::saveModelUsingTransaction($item)) {
                    $error = 'Error: DB write error.';
                }
                $message = 'Item ' . $name . ' created';
            } else {
                $error = "Error: unknown new element type.";
            }
        } else {
            $error = 'Name can\'t be empty.';
        }
        $toReturn['message'] = $message;
        $toReturn['error'] = $error;
        $toReturn['p1_force_update'] = 1;
        $toReturn['p2_force_update'] = 1;
        $toReturn['p1'] = self::makeTabData($data['p1_cat']); 
        $toReturn['p2'] = self::makeTabData($data['p2_cat']);
        $toReturn['debug'] = self::$debug['db'];
        return $toReturn;
    }
    
    public static function openAction($data)
    {
        $error = '';
        $message = '';
        $toReturn['message'] = $message;
        $toReturn['error'] = $error;
        $toReturn['p1_force_update'] = 0;
        $toReturn['p2_force_update'] = 0;
        $toReturn['p1'] = self::makeTabData($data['p1_cat']); 
        $toReturn['p2'] = self::makeTabData($data['p2_cat']);
        return $toReturn;
    }
    
    public static function copyAction($data)
    {
        $error = '';
        $message = '';
        $from = $data['from'];
        $to = $data['to'];
        $from_to_cats = GoodsCategories::findAll([$from, $to]);
        if (!(count($from_to_cats) < 2)) {
            $goods_categories = $data['entries']['categories'];
            $goods = $data['entries']['items'];
            if (!empty($goods_categories)) {
                $catsToCopy = GoodsCategories::find()->where(['id' => $goods_categories])->asArray()->all();
                $dbGoodsCategories = GoodsCategories::find()->select('id,name')->where(['parent' => $to])->asArray()->all();
                $toInsert = [];
                foreach ($catsToCopy as $oneCatToCopy) {
                    foreach ($dbGoodsCategories as $oneDbCat) {
                        if ($oneCatToCopy['name'] == $oneDbCat['name']) {
                            continue 2;
                        }
                    }
                    $toInsert[] = [$oneCatToCopy['name'], $to];
                }
                $goods_categories_insert = GoodsCategories::getDb()->createCommand()->batchInsert('goods_categories', ['name', 'parent'], $toInsert);
            }
            if (!empty($goods)) {
                $itemsToCopy = Goods::find()->where(['id' => $goods])->asArray()->all();
                $dbGoods = Goods::find()->select('id,name')->where(['parent' => $to])->asArray()->all();
                $toInsert = [];
                foreach ($itemsToCopy as $oneItemToCopy) {
                    foreach ($dbGoods as $oneDbItem) {
                        if ($oneItemToCopy['name'] == $oneDbItem['name']) {
                            continue 2;
                        }
                    }
                    $toInsert[] = [$oneItemToCopy['name'], $to];
                }
                $goods_insert = Goods::getDb()->createCommand()->batchInsert('goods', ['name', 'parent'], $toInsert);
            }
            
            $transaction = GoodsCategories::getDb()->beginTransaction();
            try {
                if (!empty($goods_categories)) {
                    $goods_categories_insert->execute();
                }
                if (!empty($goods)) {
                    $goods_insert->execute();
                }
                $transaction->commit();
            } catch(\Exception $e) {
                $transaction->rollBack();
                self::$debug['db'] = $e->getMessage();
                $error = 'Error: DB write error.';
            }
        } else {
            $error = 'Error: Wrong source or destination directory.';
        }
        $toReturn['message'] = $message;
        $toReturn['error'] = $error;
        $toReturn['p1_force_update'] = 1;
        $toReturn['p2_force_update'] = 1;
        $toReturn['p1'] = self::makeTabData($data['p1_cat']); 
        $toReturn['p2'] = self::makeTabData($data['p2_cat']);
        $toReturn['debug'] = self::$debug['db'];
        return $toReturn;
    }
    
    public static function moveAction($data)
    {
        $error = '';
        $message = '';
        $from = $data['from'];
        $to = $data['to'];
        $categoriesToUpdate = [];
        $itemsToUpdate = [];
        $catsToMove = [];
        $itemsToMove = [];
        $from_to_cats = GoodsCategories::findAll([$from, $to]);
        if (!(count($from_to_cats) < 2)) {
            $goods_categories = $data['entries']['categories'];
            $goods = $data['entries']['items'];
            if (!empty($goods_categories)) {
                $catsToMove = GoodsCategories::find()->where(['id' => $goods_categories])->asArray()->all();
                $dbGoodsCategories = GoodsCategories::find()->select('id,name')->where(['parent' => $to])->asArray()->all();
                $toUpdate = [];
                foreach ($catsToMove as $oneCatToMove) {
                    if ($oneCatToMove['id'] == $to) {
                        $error .= "Can't move category " . $oneCatToMove['name'] . " into itself. Category skipped. \n";
                        continue;
                    }
                    foreach ($dbGoodsCategories as $oneDbCat) {
                        if ($oneCatToMove['name'] == $oneDbCat['name']) {
                            $error .= "Can't move category " . $oneCatToMove['name'] . ". Category with same name exists in destination category. Category skipped. \n";
                            continue 2;
                        }
                    }
                    $toUpdate[] = $oneCatToMove['id'];
                }
                $categoriesToUpdate = $toUpdate;
                $goods_categories_update = GoodsCategories::getDb()->createCommand()->update('goods_categories', ['parent' => $to], ['id' => $toUpdate]);
            }
            if (!empty($goods)) {
                $itemsToMove = Goods::find()->where(['id' => $goods])->asArray()->all();
                $dbGoods = Goods::find()->select('id,name')->where(['parent' => $to])->asArray()->all();
                $toUpdate = [];
                foreach ($itemsToMove as $oneItemToMove) {
                    foreach ($dbGoods as $oneDbItem) {
                        if ($oneItemToMove['name'] == $oneDbItem['name']) {
                            $error .= "Can't move item " . $oneItemToMove['name'] . ". Item with same name exists in destination category. Item skipped. \n";
                            continue 2;
                        }
                    }
                    $toUpdate[] = $oneItemToMove['id'];
                }
                $itemsToUpdate = $toUpdate;
                $goods_update = Goods::getDb()->createCommand()->update('goods', ['parent' => $to], ['id' => $toUpdate]);
            }
            
            $transaction = GoodsCategories::getDb()->beginTransaction();
            $success = true;
            try {
                if (!empty($goods_categories)) {
                    $goods_categories_update->execute();
                }
                if (!empty($goods)) {
                    $goods_update->execute();
                }
                $transaction->commit();
            } catch(\Exception $e) {
                $transaction->rollBack();
                self::$debug['db'] = $e->getMessage();
                $error .= 'Error: DB write error.';
                $success = false;
            }
            if ($success) {
                self::logMove($categoriesToUpdate, $itemsToUpdate, $catsToMove, $itemsToMove, $to);
            }
        } else {
            $error = 'Error: Wrong source or destination directory.';
        }
        $toReturn['message'] = $message;
        $toReturn['error'] = $error;
        $toReturn['p1_force_update'] = 1;
        $toReturn['p2_force_update'] = 1;
        $toReturn['p1'] = self::makeTabData($data['p1_cat']); 
        $toReturn['p2'] = self::makeTabData($data['p2_cat']);
        $toReturn['debug'] = self::$debug['db'];
        return $toReturn;
    }
    
        public static function renameAction($data)
    {
        $name = $data['name'];
        $type = $data['type'];
        $id = $data['id'];
        $error = '';
        $message = '';
        if ($type == 'cat') {
            $item = GoodsCategories::findOne($id);
        } else {
            $item = Goods::findOne($id);
        }
        
        if (empty($item)) {
            $error = "Error: Can't find item in DB.";
        } elseif (!empty($name)) {
            $item->name = $name;
            if (!self::saveModelUsingTransaction($item)) {
                $error = 'Error: DB write error.';
            }
            $message = 'Item ' . $name . ' renamed';
        } else {
            $error = 'Name can\'t be empty.';
        }
        
        $toReturn['message'] = $message;
        $toReturn['error'] = $error;
        $toReturn['p1_force_update'] = 1;
        $toReturn['p2_force_update'] = 1;
        $toReturn['p1'] = self::makeTabData($data['p1_cat']); 
        $toReturn['p2'] = self::makeTabData($data['p2_cat']);
        $toReturn['debug'] = self::$debug['db'];
        return $toReturn;
    }

    public static function removeAction($data)
    {
        $error = '';
        $message = '';

        $goods_categories = $data['entries']['categories'];
        $goods = $data['entries']['items'];
        
        $transaction = GoodsCategories::getDb()->beginTransaction();
        try {
            if (!empty($goods_categories)) {
                GoodsCategories::deleteAll(['id' => $goods_categories]);
            }
            if (!empty($goods)) {
                Goods::deleteAll(['id' => $goods]);
            }
            $transaction->commit();
        } catch(\Exception $e) {
            $transaction->rollBack();
            self::$debug['db'] = $e->getMessage();
            $error .= 'Error: DB write error.';
        }

        $toReturn['message'] = $message;
        $toReturn['error'] = $error;
        $toReturn['p1_force_update'] = 1;
        $toReturn['p2_force_update'] = 1;
        $toReturn['p1'] = self::makeTabData($data['p1_cat']); 
        $toReturn['p2'] = self::makeTabData($data['p2_cat']);
        $toReturn['debug'] = self::$debug['db'];
        return $toReturn;
    }
    
    public static function getlogAction($data)
    {
        $error = '';
        $message = '';
        $id = $data['id'];
        $type = $data['type'];
        $item = [];
        if ($type == 'cat') {
            $item = GoodsCategories::findOne($id);
            $selector = 'goods_category_id';
        } else {
            $item = Goods::findOne($id);
            $selector = 'goods_id';
        }
        
        if (empty($item)) {
            $error = "Error: Can't find item in DB.";
        } else {
            $toReturn['name'] = $item->name;
            $logEntries = Log::find()->where([$selector => $id])->asArray()->all();
            // TODO complete categories names adding
            /*
            $categoryIds = [];
            foreach ($logEntries as $logEntry) {
                $categoryIds[] = trim(end(explode(':', $logEntry['action'])));
            }
            if (!empty($categoryIds)) {
                $categories = GoodsCategories::find()->where(['id' => $categoryIds])->asArray()->all();
                foreach ($logEntries as $logEntry) {
                    $exploded = explode(':', $logEntry['action']);
                    if (isset($categories[''])) {
                        
                    }
                    end($array);
                    $key = key($array);
                }
            }
            */
            $toReturn['log'] = $logEntries;
            
        }
        $toReturn['message'] = $message;
        $toReturn['error'] = $error;
        return $toReturn;
    }
    
    
    
    public static function saveModelUsingTransaction(\yii\db\ActiveRecord $model)
    {
        $transaction = $model->getDb()->beginTransaction();
            try {
                $model->save();
                $transaction->commit();
            } catch(\Exception $e) {
                $transaction->rollBack();
                self::$debug['db'] = $e->getMessage();
                return false;
            }
        return true;
    }

    public static function makeTabData($categoryId = 1)
    {
        $tabData = [];
        $tabData['parent'] = 0;
        if (empty($categoryId) || !is_numeric($categoryId) || $categoryId == 0 || $categoryId == 1) {
            $categoryId = 1;
            $tabData['cat_id'] = 1;
        } else {
            $tabData['cat_id'] = $categoryId;
            $categoryModel = GoodsCategories::find()->select('id,parent')->where(['id' => $categoryId])->asArray()->one();
            if (is_numeric($categoryModel['parent'])) {
                $tabData['parent'] = $categoryModel['parent'];
            }
        }
        $tabData['path'] = self::makePath($categoryId);
        $tabData['categories'] = GoodsCategories::find()->select('id,name')->where(['parent' => $categoryId])->asArray()->orderBy('name')->all();
        $tabData['items'] = Goods::find()->select('id,name')->where(['parent' => $categoryId])->asArray()->orderBy('name')->all();
        return $tabData;
    }
    
    public static function makePath($categoryId)
    {
        if (!$categoryId) {
            return '/';
        }
        return '/' . self::getPath($categoryId);
    }
    
    public static function getPath($categoryId)
    {
        $item = GoodsCategories::find()->select('id, name, parent')->where(['id' => $categoryId])->asArray()->one();
        $path = $item['name'];
        $parent = $item['parent'];
        if ($parent) {
            $parentPath = self::getPath($parent) . ' /';
            $path = $parentPath . $path;
        }
        return $path;
    }
    
    public function prepareManager()
    {
        $this->rootCategories = GoodsCategories::find()->where(['parent' => 1])->asArray()->orderBy('name')->all();
        $this->rootGoods = Goods::find()->where(['parent' => 1])->asArray()->orderBy('name')->all();
    }
    
    static protected function logMove($categoriesToUpdate, $itemsToUpdate, $catsToMove, $itemsToMove, $to)
    {
        foreach ($catsToMove as $oneCategoryFromDb) {
            if (in_array($oneCategoryFromDb['id'], $categoriesToUpdate)) {
                $logEntry = new Log();
                $logEntry->user = Yii::$app->user->identity->email;
                $logEntry->action = "Moved category, new category: ".$to;
                $logEntry->goods_category_id = $oneCategoryFromDb['id'];
                $logEntry->entity_name = $oneCategoryFromDb['name'];
                $logEntry->save();
            }
        }
        foreach ($itemsToMove as $oneItemFromDb) {
            if (in_array($oneItemFromDb['id'], $itemsToUpdate)) {
                $logEntry = new Log();
                $logEntry->user = Yii::$app->user->identity->email;
                $logEntry->action = "Moved item, new category: ".$to;
                $logEntry->goods_id = $oneItemFromDb['id'];
                $logEntry->entity_name = $oneItemFromDb['name'];
                $logEntry->save();
            }
        }
    }
}