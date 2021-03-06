<?php

namespace app\controllers;

use Yii;
use yii\db\Expression;
use yii\db\Query;
use yii\data\ActiveDataProvider;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\ForbiddenHttpException;
use yii\filters\VerbFilter;
use app\models\ContestAnnouncement;
use app\models\ContestUser;
use app\models\Contest;
use app\models\Solution;
use app\models\SolutionSearch;
use app\models\Discuss;

/**
 * ContestController implements the CRUD actions for Contest model.
 */
class ContestController extends Controller
{
    public $layout = 'contest';
    /**
     * @inheritdoc
     */
    public function behaviors()
    {
        return [
            'verbs' => [
                'class' => VerbFilter::className(),
                'actions' => [
                    'delete' => ['POST'],
                ],
            ],
        ];
    }

    /**
     * Lists all Contest models.
     * @return mixed
     */
    public function actionIndex()
    {
        $this->layout = 'main';
        $dataProvider = new ActiveDataProvider([
            'query' => Contest::find()->where([
                'status' => Contest::STATUS_VISIBLE
            ])->andWhere([
                '<>', 'type', Contest::TYPE_HOMEWORK
            ])->orderBy(['id' => SORT_DESC]),
        ]);

        return $this->render('index', [
            'dataProvider' => $dataProvider,
        ]);
    }

    /**
     * 用户在比赛中的提交记录
     * @param $id
     * @return mixed
     */
    public function actionStatus($id)
    {
        $model = $this->findModel($id);
        $searchModel = new SolutionSearch();

        return $this->render('/contest/status', [
            'model' => $model,
            'searchModel' => $searchModel,
            'dataProvider' => $searchModel->search(Yii::$app->request->queryParams, $model->id)
        ]);
    }

    /**
     * 显示用户在某道题上的提交
     * @param $pid
     * @param $cid
     * @return mixed
     * @throws ForbiddenHttpException if the model cannot be viewed
     */
    public function actionSubmission($pid, $cid, $uid)
    {
        $this->layout = false;
        $model = $this->findModel($cid);

        if ($model->runStatus != Contest::STATUS_ENDED && !Yii::$app->user->isGuest && Yii::$app->user->id != $model->created_by) {
            throw new ForbiddenHttpException('You are not allowed to perform this action.');
        }
        $submissions = Yii::$app->db
            ->createCommand(
                'SELECT id, result, created_at FROM {{%solution}} WHERE problem_id=:pid AND contest_id=:cid AND created_by=:uid ORDER BY id DESC',
                [':pid' => $pid, ':cid' => $model->id, ':uid' => $uid]
            )->queryAll();
        return $this->render('submission', [
            'submissions' => $submissions
        ]);
    }

    /**
     * 显示注册参赛的用户
     * @param integer $id
     * @return mixed
     */
    public function actionUser($id)
    {
        $this->layout = 'main';
        $model = $this->findModel($id);
        $provider = new ActiveDataProvider([
            'query' => ContestUser::find()->where(['contest_id' => $model->id])->with('user')->with('userProfile'),
            'pagination' => [
                'pageSize' => 100
            ]
        ]);

        return $this->render('user', [
            'model' => $model,
            'provider' => $provider
        ]);
    }

    /**
     * 注册比赛的页面
     * @param integer $id
     * @param integer $register 等于 0 什么也不做，等于 1 就将当前用户注册到比赛列表中
     * @return mixed
     * @throws NotFoundHttpException if the contest cannot be found
     * @throws ForbiddenHttpException if the contest if offline
     */
    public function actionRegister($id, $register = 0)
    {
        $this->layout = 'main';
        if (Yii::$app->user->isGuest) {
            return $this->redirect(['/site/login']);
        }
        $model = $this->findModel($id);

        // 线下赛只能在后台加入，在此处不给注册
        if ($model->scenario == Contest::SCENARIO_OFFLINE) {
            throw new ForbiddenHttpException('You are not allowed to perform this action.');
        }

        if ($register == 1 && !$model->isUserInContest()) {
            Yii::$app->db->createCommand()->insert('{{%contest_user}}', [
                'contest_id' => $model->id,
                'user_id' => Yii::$app->user->id
            ])->execute();
            Yii::$app->session->setFlash('success', '成功注册');
            return $this->redirect(['/site/index']);
        }
        return $this->render('register', [
            'model' => $model
        ]);
    }

    /**
     * Displays a single Contest model.
     * @param integer $id
     * @return mixed
     * @throws NotFoundHttpException if the action cannot be found
     */
    public function actionView($id)
    {
        $model = $this->findModel($id);

        $dataProvider = new ActiveDataProvider([
            'query' => ContestAnnouncement::find()->where(['contest_id' => $model->id]),
        ]);

        return $this->render('view', [
            'model' => $model,
            'dataProvider' => $dataProvider
        ]);
    }

    public function actionEditorial($id)
    {
        $model = $this->findModel($id);

        if ($model->getRunStatus() == Contest::STATUS_ENDED) {
            return $this->render('/contest/editorial', [
                'model' => $model
            ]);
        }

        throw new ForbiddenHttpException('You are not allowed to perform this action.');
    }

    /**
     * 比赛问题答疑页面
     * @param $id
     * @param int $cid 该值等于 -1 时，显示所有的答疑列表，否则显示具体某个答疑
     * @return string|\yii\web\Response
     * @throws NotFoundHttpException
     */
    public function actionClarify($id, $cid = -1)
    {
        $model = $this->findModel($id);
        $newClarify = new Discuss();
        $discuss = null;
        $dataProvider = new ActiveDataProvider([
            'query' => ContestAnnouncement::find()->where(['contest_id' => $model->id]),
        ]);

        if ($cid != -1) {
            if (($discuss = Discuss::findOne(['id' => $cid, 'entity_id' => $model->id, 'entity' => Discuss::ENTITY_CONTEST])) === null) {
                throw new NotFoundHttpException('The requested page does not exist.');
            }
        }
        if (!Yii::$app->user->isGuest && $newClarify->load(Yii::$app->request->post())) {
            // 判断是否已经参赛
            if (!$model->isUserInContest()) {
                Yii::$app->session->setFlash('error', 'Submit Failed. You did not register for the contest.');
                return $this->refresh();
            }
            $newClarify->entity = Discuss::ENTITY_CONTEST;
            $newClarify->entity_id = $model->id;
            if ($discuss !== null) {
                if (empty($newClarify->content)) {
                    Yii::$app->session->setFlash('error', '内容不能为空');
                    return $this->refresh();
                }
                $newClarify->parent_id = $discuss->id;
                $discuss->updated_at = new Expression('NOW()');
                $discuss->update();
            } else if (empty($newClarify->title)) {
                Yii::$app->session->setFlash('error', '标题不能为空');
                return $this->refresh();
            }
            $newClarify->status = Discuss::STATUS_PRIVATE;
            $newClarify->save();
            Yii::$app->session->setFlash('success', 'Submit Successfully');
            return $this->refresh();
        }
        $query = Discuss::find()
            ->where(['parent_id' => 0, 'entity_id' => $model->id, 'entity' => Discuss::ENTITY_CONTEST, 'status' => 1])
            ->with('user')
            ->orderBy('created_at DESC');
        if (!Yii::$app->user->isGuest) {
            $query->orWhere(['parent_id' => 0, 'entity_id' => $model->id, 'entity' => Discuss::ENTITY_CONTEST, 'created_by' => Yii::$app->user->id]);
        }
        $clarifies = new ActiveDataProvider([
            'query' => $query,
        ]);

        if ($discuss != null) {
            return $this->render('/contest/clarify_view', [
                'newClarify' => $newClarify,
                'clarify' => $discuss,
                'model' => $model
            ]);
        } else {
            return $this->render('/contest/clarify', [
                'model' => $model,
                'clarifies' => $clarifies,
                'newClarify' => $newClarify,
                'discuss' => $discuss,
                'dataProvider' => $dataProvider
            ]);
        }
    }

    /**
     * 比赛榜单
     * @param integer $id
     * @return mixed
     */
    public function actionStanding($id)
    {
        $model = $this->findModel($id);
        return $this->render('standing', [
            'model' => $model
        ]);
    }

    /**
     * 显示比赛问题
     * @param integer $id Contest Id
     * @param integer $pid Problem Id
     * @return mixed
     */
    public function actionProblem($id, $pid = 0)
    {
        $model = $this->findModel($id);
        $solution = new Solution();

        $problem = $model->getProblemById(intval($pid));

        if (!Yii::$app->user->isGuest && $solution->load(Yii::$app->request->post())) {
            if (!$model->isUserInContest()) {
                Yii::$app->session->setFlash('error', 'Submit Failed. You did not register for the contest.');
                return $this->refresh();
            }
            if ($model->getRunStatus() == Contest::STATUS_NOT_START) {
                Yii::$app->session->setFlash('error', 'The contest has not started.');
                return $this->refresh();
            }
            if ($model->getRunStatus() == Contest::STATUS_ENDED) {
                Yii::$app->session->setFlash('error', 'The contest is over.');
                return $this->refresh();
            }
            $solution->problem_id = $problem['id'];
            $solution->contest_id = $model->id;
            $solution->status = Solution::STATUS_HIDDEN;
            $solution->save();
            Yii::$app->session->setFlash('success', 'Submit Successfully');
            return $this->refresh();
        }
        $submissions = [];
        if (!Yii::$app->user->isGuest) {
            $submissions = (new Query())->select('created_at, result, id')
                ->from('{{%solution}}')
                ->where([
                    'problem_id' => $problem['id'],
                    'contest_id' => $model->id,
                    'created_by' => Yii::$app->user->id
                ])
                ->orderBy('id DESC')
                ->limit(10)
                ->all();
        }
        if (Yii::$app->request->isAjax) {
            return $this->renderAjax('/contest/problem', [
                'model' => $model,
                'solution' => $solution,
                'problem' => $problem,
                'submissions' => $submissions
            ]);
        } else {
            return $this->render('/contest/problem', [
                'model' => $model,
                'solution' => $solution,
                'problem' => $problem,
                'submissions' => $submissions
            ]);
        }
    }

    /**
     * Finds the Contest model based on its primary key value.
     * If the model is not found, a 404 HTTP exception will be thrown.
     * @param integer $id
     * @return Contest the loaded model
     * @throws NotFoundHttpException if the model cannot be found
     * @throws ForbiddenHttpException if the model cannot be viewed
     */
    protected function findModel($id)
    {
        if (($model = Contest::findOne($id)) !== null) {
            if ($model->status == Contest::STATUS_VISIBLE || !Yii::$app->user->isGuest && Yii::$app->user->id === $model->created_by) {
                return $model;
            } else {
                throw new ForbiddenHttpException('You are not allowed to perform this action.');
            }
        }
        throw new NotFoundHttpException('The requested page does not exist.');
    }
}
