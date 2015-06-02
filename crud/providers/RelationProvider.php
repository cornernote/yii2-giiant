<?php
/**
 * Created by PhpStorm.
 * User: tobias
 * Date: 14.03.14
 * Time: 10:21
 */

namespace schmunk42\giiant\crud\providers;

use yii\db\ActiveRecord;
use yii\db\ColumnSchema;
use yii\helpers\Inflector;

class RelationProvider extends \schmunk42\giiant\base\Provider
{
    /**
     * @var null can be null (default) or `select2`
     */
    public $inputWidget = null;

    /**
     * Formatter for relation form inputs
     *
     * Renders a drop-down list for a `hasOne`/`belongsTo` relation
     *
     * @param $column
     *
     * @return null|string
     */
    public function activeField($column)
    {
        $relation = $this->generator->getRelationByColumn($this->generator->modelClass, $column);
        if ($relation) {
            switch (true) {
                case (!$relation->multiple):
                    $pk     = key($relation->link);
                    $name   = $this->generator->getModelNameAttribute($relation->modelClass);
                    $method = __METHOD__;
                    switch ($this->inputWidget) {
                        case 'select2':
                            $code = <<<EOS
// generated by {$method}
\$form->field(\$model, '{$column->name}')->widget(\kartik\select2\Select2::classname(), [
    'name' => 'class_name',
    'model' => \$model,
    'attribute' => '{$column->name}',
    'data' => \yii\helpers\ArrayHelper::map({$relation->modelClass}::find()->all(), '{$pk}', '{$name}'),
    'options' => [
        'placeholder' => {$this->generator->generateString('Type to autocomplete')},
        'multiple' => false,
    ]
]);
EOS;
                            break;
                        default:
                            $code = <<<EOS
// generated by {$method}
\$form->field(\$model, '{$column->name}')->dropDownList(
    \yii\helpers\ArrayHelper::map({$relation->modelClass}::find()->all(), '{$pk}', '{$name}'),
    ['prompt' => {$this->generator->generateString('Select')}]
);
EOS;
                            break;
                    }
                    return $code;
                default:
                    return null;

            }
        }
    }

    /**
     * Formatter for detail view relation attributes
     *
     * Renders a link to the related detail view
     *
     * @param $column ColumnSchema
     *
     * @return null|string
     */
    public function attributeFormat($column)
    {
        # handle columns with a primary key, to create links in pivot tables (changed at 0.3-dev; 03.02.2015)
        # TODO double check with primary keys not named `id` of non-pivot tables
        # TODO Note: condition does not apply in every case
        if ($column->isPrimaryKey) {
            #return null; #TODO: double check with primary keys not named `id` of non-pivot tables
        }

        $relation = $this->generator->getRelationByColumn($this->generator->modelClass, $column);
        if ($relation) {
            if ($relation->multiple) {
                return null;
            }
            $title          = $this->generator->getModelNameAttribute($relation->modelClass);
            $route          = $this->generator->createRelationRoute($relation, 'view');
            $relationGetter = 'get' . Inflector::id2camel(
                    str_replace('_id', '', $column->name),
                    '_'
                ) . '()'; // TODO: improve detection
            $params         = "'id' => \$model->{$column->name}";

            $relationModel   = new $relation->modelClass;
            $pks             = $relationModel->primaryKey();
            $paramArrayItems = "";
            foreach ($pks as $attr) {
                $paramArrayItems .= "'{$attr}' => \$model->{$relationGetter}->one()->{$attr},";
            }

            $method = __METHOD__;
            $code   = <<<EOS
// generated by {$method}
[
    'format' => 'html',
    'attribute' => '$column->name',
    'value' => (\$model->{$relationGetter}->one() ? Html::a(\$model->{$relationGetter}->one()->{$title}, ['{$route}', {$paramArrayItems}]) : '<span class="label label-warning">?</span>'),
]
EOS;
            return $code;
        }
    }

    /**
     * Formatter for relation grid columns
     *
     * Renders a link to the related detail view
     *
     * @param $column ColumnSchema
     * @param $model ActiveRecord
     *
     * @return null|string
     */
    public function columnFormat($column, $model)
    {
        # handle columns with a primary key, to create links in pivot tables (changed at 0.3-dev; 03.02.2015)
        # TODO double check with primary keys not named `id` of non-pivot tables
        # TODO Note: condition does not apply in every case
        if ($column->isPrimaryKey) {
            #return null;
        }

        $relation = $this->generator->getRelationByColumn($model, $column);
        if ($relation) {
            if ($relation->multiple) {
                return null;
            }
            $title          = $this->generator->getModelNameAttribute($relation->modelClass);
            $route          = $this->generator->createRelationRoute($relation, 'view');
            $method         = __METHOD__;
            $relationGetter = 'get' . Inflector::id2camel(
                    str_replace('_id', '', $column->name),
                    '_'
                ) . '()'; // TODO: improve detection

            $pk              = key($relation->link);
            $relationModel   = new $relation->modelClass;
            $pks             = $relationModel->primaryKey();
            $paramArrayItems = "";
            foreach ($pks as $attr) {
                $paramArrayItems .= "'{$attr}' => \$rel->{$attr},";
            }
            $code = <<<EOS
// generated by {$method}
[
    'class' => yii\\grid\\DataColumn::className(),
    'attribute' => '{$column->name}',
    'value' => function(\$model) {
        if (\$rel = \$model->{$relationGetter}->one()) {
            return yii\helpers\Html::a(\$rel->{$title}, ['{$route}', {$paramArrayItems}], ['data-pjax' => 0]);
        } else {
            return '';
        }
    },
    'format' => 'raw',
]
EOS;
            return $code;
        }
    }


    /**
     * Renders a grid view for a given relation
     *
     * @param $name
     * @param $relation
     * @param bool $showAllRecords
     *
     * @return mixed|string
     */
    public function relationGrid($name, $relation, $showAllRecords = false)
    {
        $model = new $relation->modelClass;

        // column counter
        $counter = 0;
        $columns = '';

        if (!$this->generator->isPivotRelation($relation)) {
            // hasMany relations
            $template          = '{view} {update}';
            $deleteButtonPivot = '';
        } else {
            // manyMany relations
            $template          = '{view} {delete}';
            $deleteButtonPivot = <<<EOS
'delete' => function (\$url, \$model) {
                return yii\helpers\Html::a('<span class="glyphicon glyphicon-remove"></span>', \$url, [
                    'class' => 'text-danger',
                    'title'         => {$this->generator->generateString('Remove')},
                    'data-confirm'  => {$this->generator->generateString(
                'Are you sure you want to delete the related item?'
            )},
                    'data-method' => 'post',
                    'data-pjax' => '0',
                ]);
            },
'view' => function (\$url, \$model) {
                return yii\helpers\Html::a(
                    '<span class="glyphicon glyphicon-cog"></span>',
                    \$url,
                    [
                        'data-title'  => {$this->generator->generateString('View Pivot Record')},
                        'data-toggle' => 'tooltip',
                        'data-pjax'   => '0',
                        'class'       => 'text-muted',
                    ]
                );
            },
EOS;
        }

        $reflection   = new \ReflectionClass($relation->modelClass);
        $controller   = $this->generator->pathPrefix . Inflector::camel2id($reflection->getShortName(), '-', true);
        $actionColumn = <<<EOS
        [
            'class' => 'yii\grid\ActionColumn',
            'template' => '$template',
            'contentOptions' => ['nowrap' => 'nowrap'],
            'urlCreator' => function(\$action, \$model, \$key, \$index) {
                // using the column name as key, not mapping to 'id' like the standard generator
                \$params = is_array(\$key) ? \$key : [\$model->primaryKey()[0] => (string)\$key];
                \$params[0] = '$controller' . '/' . \$action;
                return Url::toRoute(\$params);
            },
            'buttons' => [
                $deleteButtonPivot
            ],
            'controller' => '$controller'
        ]
EOS;

        // add action column
        $columns .= $actionColumn . ",\n";

        // prepare grid column formatters
        foreach ($model->attributes AS $attr => $value) {
            // max seven columns
            if ($counter > 8) {
                continue;
            }
            // skip virtual attributes
            if (!isset($model->tableSchema->columns[$attr])) {
                continue;
            }
            // don't show current model
            if (key($relation->link) == $attr) {
                continue;
            }

            $code = $this->generator->columnFormat($model->tableSchema->columns[$attr], $model);
            if ($code == false) {
                continue;
            }
            $columns .= $code . ",\n";
            $counter++;
        }

        $query          = $showAllRecords ?
            "'query' => \\{$relation->modelClass}::find()" :
            "'query' => \$model->get{$name}()";
        $pageParam      = Inflector::slug("page-{$name}");
        $firstPageLabel = $this->generator->generateString('First');
        $lastPageLabel  = $this->generator->generateString('Last');
        $code           = '\'<div class="table-responsive">\' . ';
        $code .= <<<EOS
\\yii\\grid\\GridView::widget([
    'layout' => '{summary}{pager}<br/>{items}{pager}',
    'dataProvider' => new \\yii\\data\\ActiveDataProvider([{$query}, 'pagination' => ['pageSize' => 20, 'pageParam' => '{$pageParam}']]),
    'pager' => [
        'class' => yii\widgets\LinkPager::className(),
        'firstPageLabel' => {$firstPageLabel},
        'lastPageLabel' => {$lastPageLabel}
    ],
    'columns' => [
$columns]
])
EOS;
        $code .= ' . \'</div>\' ';
        return $code;
    }
}
