<?php

namespace backend\controllers;


use backend\components\helpers\FileUploadHelper;
use backend\models\serviceType\ServiceType;
use backend\models\whiteLabelPage\WhiteLabelPageSearch;
use common\models\Account;
use common\models\AccountInfo;
use common\models\AccountPolicies;
use common\models\WhiteLabelPage;
use Yii;
use yii\base\Exception;
use yii\db\Query;
use yii\web\Controller;
use yii\web\Response;
use yii\web\UploadedFile;
use yii\widgets\ActiveForm;

/**
 * Class CmsController
 * @package backend\controllers
 */
class CmsController extends Controller
{
    /**
     * List Quote Page CMS
     */
    public function actionIndex()
    {
        $getParams = Yii::$app->request->get();

        $searchModel = new WhiteLabelPageSearch();
        $dataProvider = $searchModel->search($getParams);

        $this->view->title = Yii::t('app','Quote Page CMS');

        return $this->render('index',[
            'dataProvider' => $dataProvider,
            'searchModel' => $searchModel
        ]);
    }

    /**
     * @return mixed
     */
    public function actionCreate()
    {

        $post = Yii::$app->request->post();
        $model = new WhiteLabelPage;
        $serviceTypes = ServiceType::find()->asArray()->all();
        $modelAccountInfo = new AccountInfo();
        $modelAccountPolicies = new AccountPolicies();

        if(!empty($post)){
            $validate = false;

            if ($model->load($post)) {
                $model->imageLogo = UploadedFile::getInstance($model, 'imageLogo');
                $model->imagePrimary = UploadedFile::getInstance($model, 'imagePrimary');

                $modelAccountInfo = AccountInfo::find()->where(['account_id' => $model->account_id])->one();
                $modelAccountPolicies = AccountPolicies::find()->where(['account_id' => $model->account_id])->one();

                if (!$modelAccountPolicies) {
                    $modelAccountPolicies = new AccountPolicies(['account_id' => $model->account_id]);
                }

                if ($modelAccountInfo->load($post) && $modelAccountInfo->validate()
                    && $modelAccountPolicies->load($post) && $modelAccountPolicies->validate())
                {
                    $validate = true;
                }

            }

            if($model->validate() && $validate){

                $transaction = Yii::$app->db->beginTransaction();

                try {

                    if(!empty($model->imageLogo)){
                        $saveLogo = FileUploadHelper::saveImage($model->imageLogo,$oldId=null);
                        $model->logo_id = $saveLogo;
                        $model->imageLogo = null;
                    }

                    if(!empty($model->imagePrimary)){
                        $savePrimary = FileUploadHelper::saveImage($model->imagePrimary,$oldId=null);
                        $model->primary_image_id = $savePrimary;
                        $model->imagePrimary = null;
                    }

                    if(!$model->save()){
                        throw new Exception(Yii::t('app','Error during page saving'));
                    }

                    if(!$modelAccountInfo->save()){
                        throw new Exception(Yii::t('app','Error during account info updating'));
                    }

                    if($modelAccountPolicies && !$modelAccountPolicies->save()){
                        throw new Exception(Yii::t('app','Error during account policies updating'));
                    }

                    $transaction->commit();

                    Yii::$app->session->setFlash('success', Yii::t('app','Page created successfully.'));

                    return $this->redirect('index');
                } catch (Exception $e){
                    Yii::error($e->getMessage(), __METHOD__);
                    $transaction->rollBack();
                    Yii::$app->session->setFlash('error', Yii::t('app',$e->getMessage()));
                }

            }
        }

        return $this->render('create',[
            'model' => $model,
            'serviceTypes' => $serviceTypes,
            'modelAccountInfo' => $modelAccountInfo,
            'modelAccountPolicies' => $modelAccountPolicies
        ]);
    }

    /**
     * @param null $q
     * @param null $id
     * @return array
     */
    public function actionGetAccountNameAutoComplete($q = null, $id = null)
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $out = ['results' => ['id' => '', 'text' => '']];
        if (!is_null($q)) {
            $query = new Query;
            $query->select('account_id AS id, agency_name AS text')
                ->from('account_info')
                ->where(['like', 'agency_name', $q]);
            $command = $query->createCommand();
            $data = $command->queryAll();
            $out['results'] = array_values($data);
        }
        elseif ($id > 0) {
            $out['results'] = ['id' => $id, 'text' => Account::findOne($id)->username];
        }
        return $out;
    }

    /**
     * @return bool|string
     */
    public function actionGetAccountInfoFields()
    {
        if (Yii::$app->request->isAjax) {
            if (!empty(Yii::$app->request->post('account_id'))) {
                Yii::$app->response->format = Response::FORMAT_JSON;
                $modelAccountInfo = AccountInfo::findOne(Yii::$app->request->post('account_id'));
                $modelAccountPolicies = AccountPolicies::findOne(Yii::$app->request->post('account_id'));

                return $this->renderAjax('add-account-info-data', ['modelAccountInfo' => $modelAccountInfo,
                    'modelAccountPolicies' => $modelAccountPolicies, 'form' => new ActiveForm()]);
            }
        }

        return false;
    }

    /**
     * @param $id
     * @return mixed
     */
    public function actionEdit($id)
    {
        $post = Yii::$app->request->post();

        $model = WhiteLabelPage::findOne($id);
        $modelAccountInfo = AccountInfo::find()->where(['account_id' => $model->account_id])->one();
        $modelAccountPolicies = AccountPolicies::find()->where(['account_id' => $model->account_id])->one();
        $serviceTypes = ServiceType::find()->asArray()->all();


        if(!empty($post)){
            if ($model->load($post) && $modelAccountInfo->load($post) && $modelAccountPolicies->load($post)) {

                $model->imageLogo = UploadedFile::getInstance($model, 'imageLogo');
                $model->imagePrimary = UploadedFile::getInstance($model, 'imagePrimary');

                if($model->validate() && $modelAccountInfo->validate() && $modelAccountPolicies->validate()){
                    $transaction = Yii::$app->db->beginTransaction();
                    try {

                        if(!$modelAccountInfo->save()){
                            throw new Exception(Yii::t('app','Error during account info saving'));
                        }

                        if(!$modelAccountPolicies->save()){
                            throw new Exception(Yii::t('app','Error during account policies saving'));
                        }

                        if(!empty($model->imageLogo)){
                            $oldId = !empty($model->logo_id) ? $model->logo_id : null;
                            $saveLogo = FileUploadHelper::saveImage($model->imageLogo, $oldId);
                            $model->logo_id = $saveLogo;
                            $model->imageLogo = null;
                        }

                        if(!empty($model->imagePrimary)){
                            $oldId = !empty($model->primary_image_id) ? $model->primary_image_id : null;
                            $savePrimary = FileUploadHelper::saveImage($model->imagePrimary, $oldId);
                            $model->primary_image_id = $savePrimary;
                            $model->imagePrimary = null;
                        }

                        if(!$model->save()){
                            throw new Exception(Yii::t('app','Error during page saving'));
                        }

                        $transaction->commit();

                        Yii::$app->session->setFlash('success', Yii::t('app','Page updated successfully.'));

                        return $this->redirect(Yii::$app->request->referrer);

                    } catch (Exception $e){
                        Yii::error($e->getMessage(), __METHOD__);
                        $transaction->rollBack();
                        Yii::$app->session->setFlash('error', Yii::t('app',$e->getMessage()));
                    }
                }
            }

        }

        return $this->render('edit',[
            'model' => $model,
            'modelAccountInfo' => $modelAccountInfo,
            'modelAccountPolicies' => $modelAccountPolicies,
            'serviceTypes' => $serviceTypes
        ]);
    }
}