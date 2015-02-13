<?php

namespace app\data\components;

use app\components\Helper;
use app\models\Object;
use app\models\ObjectPropertyGroup;
use app\models\Property;
use app\models\PropertyGroup;
use app\models\PropertyHandler;
use app\models\PropertyStaticValues;
use devgroup\TagDependencyHelper\ActiveRecordHelper;
use Yii;
use yii\base\Component;
use yii\base\InvalidParamException;
use yii\db\ActiveRecord;
use yii\helpers\ArrayHelper;

abstract class Import extends Component
{
    protected $object;
    protected $properties = null;
    public $filename;
    public $addPropertyGroups = [];
    public $createIfNotExists = false;
    public $multipleValuesDelimiter = '|';
    public $additionalFields = [];

    abstract public function getData($importFields);
    abstract public function setData($exportFields);

    /**
     * @param array $config
     * @return ImportCsv
     * @throws \Exception
     */
    public static function createInstance($config)
    {
        if (isset($config['type'])) {
            $type = $config['type'];
            unset($config['type']);

            switch ($type) {
                case 'csv':
                    return new ImportCsv($config);
                case 'excelCsv':
                    return new ImportExcelCsv($config);
                default:
                    throw new \Exception('Unsupported type');
            }
        } else {
            throw new InvalidParamException('Parameter \'type\' is not set');
        }
    }

    public static function getFields($objectId)
    {
        $fields = [];
        $object = Object::findById($objectId);
        if ($object) {
            $fields['object'] = array_diff((new $object->object_class)->attributes(), ['id']);
            $fields['object'] = array_combine($fields['object'], $fields['object']);
            $fields['property'] = ArrayHelper::getColumn(static::getProperties($objectId), 'key');
            $fields['additionalFields'] = [];
        }
        return $fields;
    }

    protected static function getProperties($objectId)
    {
        $properties = [];
        $groups = PropertyGroup::getForObjectId($objectId);
        foreach ($groups as $group) {
            $props = Property::getForGroupId($group->id);
            foreach ($props as $prop) {
                $properties[] = $prop;
            }
        }
        return $properties;
    }

    public function getObject()
    {
        return $this->object;
    }

    public function __construct($config = [])
    {
        if (!isset($config['object'])) {
            throw new InvalidParamException('Parameters \'object\' is not set');
        }
        $this->object = $config['object'];
        if (is_numeric($this->object)) {
            $this->object = Object::findById($this->object);
        } elseif (!($this->object instanceof Object)) {
            throw new InvalidParamException('Parameter "object" not Object or numeric');
        }
        unset($config['object']);
        parent::__construct($config);
    }

    protected function save($objectId, $object, $objectFields = [], $properties = [], $propertiesFields = [], $row=[], $titleFields=[], $notCreatedFields = [])
    {
        try {
            $rowFields = array_combine(array_keys($titleFields), $row);
        } catch(\Exception $e) {
            echo "title fields: ";
            var_dump(array_keys($titleFields));
            echo "\n\nRow:";
            var_dump($row);
            echo "\n\n";
            throw $e;
        }

        $class = $this->object->object_class;
        if ($objectId > 0) {
            /** @var ActiveRecord $objectModel */
            $objectModel = $class::findOne($objectId);
            if (!is_object($objectModel)) {
                if ($this->createIfNotExists === true) {
                    $objectModel = new $class;
                    $objectModel->id = $objectId;
                } else {
                    return;
                }
            }
            $objectData = [];
            foreach ($objectFields as $field) {
                if (isset($object[$field])) {
                    $objectData[$field] = $object[$field];
                }
            }
        } else {
            /** @var ActiveRecord $objectModel */
            $objectModel = new $class;
            $objectModel->loadDefaultValues();
            $objectData = $object;
        }
        if ($objectModel) {
            if ($objectModel instanceof ImportableInterface) {
                $objectModel->processImportBeforeSave($rowFields, $this->multipleValuesDelimiter, $this->additionalFields);
            }

            if ($objectModel->save()) {
                // add PropertyGroup to object
                if (!is_array($this->addPropertyGroups)) {
                    $this->addPropertyGroups = [];
                }
                foreach ($this->addPropertyGroups as $propertyGroupId) {
                    $model = new ObjectPropertyGroup();
                    $model->object_id = $this->object->id;
                    $model->object_model_id = $objectModel->id;
                    $model->property_group_id = $propertyGroupId;
                    $model->save();
                }
                if (count($this->addPropertyGroups) > 0) {
                    $objectModel->updatePropertyGroupsInformation();
                }

                $propertiesData = [];
                $objectModel->getPropertyGroups();

                foreach ($propertiesFields as $propertyId => $field) {
                    if (isset($properties[$field['key']])) {
                        $value = $properties[$field['key']];

                        if (isset($field['processValuesAs'])) {
                            // it is PSV in text
                            // we should convert it to ids
                            $staticValues = PropertyStaticValues::getValuesForPropertyId($propertyId);

                            $representationConversions = [
                                // from -> to
                                'text' => 'name',
                                'value' => 'value',
                                'id' => 'id',
                            ];
                            $attributeToGet = $representationConversions[$field['processValuesAs']];
                            $ids = [];
                            foreach ($value as $initial) {
                                $original = $initial;
                                $initial = mb_strtolower(trim($original));
                                $added = false;
                                foreach ($staticValues as $static) {
                                    if (mb_strtolower(trim($static[$attributeToGet])) === $initial) {
                                        $ids [] = $static['id'];
                                        $added = true;
                                    }
                                }
                                if (!$added) {
                                    // create PSV!
                                    $model = new PropertyStaticValues();
                                    $model->property_id = $propertyId;
                                    $model->name = $model->value = $model->slug = $original;
                                    $model->sort_order = 0;
                                    $model->title_append = '';
                                    if ($model->save()) {
                                        $ids[] = $model->id;
                                    }

                                    //flush cache!
                                    unset(PropertyStaticValues::$identity_map_by_property_id[$propertyId]);

                                    \yii\caching\TagDependency::invalidate(
                                        Yii::$app->cache,
                                        [
                                            \devgroup\TagDependencyHelper\ActiveRecordHelper::getObjectTag(Property::className(), $propertyId)
                                        ]
                                    );
                                }
                            }
                            $value = $ids;
                        }

                        $propertiesData[$field['key']] = $value;
                    }
                }


                if (!empty($propertiesData)) {

                    $objectModel->saveProperties(
                        [
                            "Properties_{$objectModel->formName()}_{$objectModel->id}" => $propertiesData
                        ]
                    );
                }

                if (!empty($notCreatedFields)) {
                    $object = Object::getForClass($objectModel->className());
                    if (is_object($object)) {
                        $pgName = 'New properties for ' . $object->name . ' created at';
                        $pg = PropertyGroup::find()
                            ->andWhere(['like', 'name', $pgName])
                            ->andWhere(['object_id' => $object->id])
                            ->one();

                        if (null === $pg) {
                            $pg = new PropertyGroup();
                            $pg->attributes = [
                                'object_id' => $object->id,
                                'name' => $pgName . ' ' . date("Y-m-d H:i:s"),
                                'hidden_group_title' => 1,
                            ];

                            $pg->save();
                        }

                        if ($pg->isNewRecord === false) {
                            $ph = PropertyHandler::find()->where(['name' => 'Text'])->one();
                            if (is_object($ph)) {
                                foreach ($notCreatedFields as $key => $value) {
                                    $notCreatedFields[$key] = iconv('Windows-1251', 'UTF-8', $value);

                                    $newProp = Property::find()->where(['key' => $key])->one();
                                    if(null === $newProp) {
                                        $newProp = new Property();
                                        $newProp->attributes = [
                                            'property_group_id' => $pg->id,
                                            'name' => $key,
                                            'key' => $key,
                                            'value_type' => 'STRING',
                                            'property_handler_id' => $ph->id,
                                            'has_static_values' => 0,
                                            'has_slugs_in_values' => 0,
                                            'is_eav' => 1,
                                            'handler_additional_params' => '{}',
                                        ];

                                        $newProp->save(
                                            true,
                                            [
                                                'property_group_id',
                                                'name',
                                                'key',
                                                'value_type',
                                                'property_handler_id',
                                                'has_static_values',
                                                'has_slugs_in_values',
                                                'is_eav',
                                                'handler_additional_params',
                                            ]
                                        );
                                    }
                                    unset($newProp);
                                }

                                $opg = new ObjectPropertyGroup();
                                $opg->attributes = [
                                    'object_id' => $object->id,
                                    'object_model_id' => $objectModel->id,
                                    'property_group_id' => $pg->id
                                ];

                                $opg->save();

                                $objectModel->saveProperties([
                                    'Properties_' . $objectModel->formName() . '_' . $objectModel->id => $notCreatedFields
                                ]);
                            }
                        }
                    }
                }

                if ($objectModel instanceof ImportableInterface) {
                    $objectModel->processImportAfterSave($rowFields, $this->multipleValuesDelimiter, $this->additionalFields);
                }

                if ($objectModel->hasMethod('invalidateTags')) {
                    $objectModel->invalidateTags();
                }
            } else {
                throw new \Exception('Cannot save object: ' . var_export($objectModel->errors, true) . var_export($objectData, true) . var_export($objectModel->getAttributes(), true));
            }
        }
    }
}
