<?php
App::uses('BindableBehavior', 'Filebinder.Model/Behavior');

class ImageBindableBehavior extends BindableBehavior {


    public function afterSave(Model $model, $created)
    {
        $modelName = $model->alias;

        if ($created) {
            $model_id = $model->getLastInsertId();
        } else {
            if (empty($model->bindedData[$modelName][$this->runtime[$model->alias]['primaryKey']])) {
                // SoftDeletable
                return;
            }
            $model_id = $model->bindedData[$modelName][$this->runtime[$model->alias]['primaryKey']];
        }

        $bindFields = Set::combine($model->bindFields, '/field' , '/');
        $fields = Set::extract('/field', $model->bindFields);
        $deleteFields = array();

        foreach ($fields as $field) {
            $deleteFields[] = 'delete_' . $field;
        }

        // set model_id
        foreach ($model->bindedData[$modelName] as $fieldName => $value) {
            if (in_array($fieldName, $deleteFields) && $value) {
                $delete = true;
                $fieldName = substr($fieldName, 7);

            } else if (in_array($fieldName, $fields)) {
                $delete = false;

            } else {
                continue;
            }

            if ($delete || (!$created && !empty($value['tmp_bind_path']))) {
                unset($model->data[$modelName]['delete_' . $fieldName]);

                if ($delete || $this->settings[$model->alias]['exchangeFile']) {
                    $this->deleteEntity($model, $model_id, $fieldName);

                } else {
                    $this->runtime[$model->alias]['bindedModel']->deleteAll(array(
                            'model' => $modelName,
                            'model_id' => $model_id,
                            'field_name' => $fieldName
                        ));
                }
            }

            if (!is_array($value) || empty($value['tmp_bind_path'])) {
                continue;
            }

            $bind = array();
            $bind[$this->runtime[$model->alias]['bindedModel']->primaryKey] = $value[$this->runtime[$model->alias]['bindedModel']->primaryKey];
            $bind['model_id'] = $model_id;

            $this->runtime[$model->alias]['bindedModel']->create();
            if (!$this->runtime[$model->alias]['bindedModel']->save($bind)) {
                return false;
            }

            $baseDir = empty($bindFields[$fieldName]['filePath']) ? $this->settings[$model->alias]['filePath'] : $bindFields[$fieldName]['filePath'];
            if ($baseDir) {
                $filePath = $baseDir . $model->transferTo(array_diff_key(array('model_id' => $model_id) + $value, Set::normalize(array('tmp_bind_path'))));
            } else {
                $filePath = false;
            }
            $tmpFile = $value['tmp_bind_path'];

            if (file_exists($tmpFile) && is_file($tmpFile)) {

                /**
                 * Local file
                 */
                if ($filePath) {
                    $currentMask = umask();
                    umask(0);
                    if (!is_dir(dirname($filePath))) {
                        mkdir(dirname($filePath), $this->settings[$model->alias]['dirMode'], true);
                    }
                    if (!copy($tmpFile, $filePath) || !chmod($filePath, $this->settings[$model->alias]['fileMode'])) {
                        @unlink($tmpFile);
                        umask($currentMask);
                        return false;
                    }
                    umask($currentMask);
                }

                /**
                 * S3 storage
                 */
                $s3Storage = in_array(BindableBehavior::STORAGE_S3, (array)$this->settings[$model->alias]['storage']);
                if ($s3Storage) {
                    if (!class_exists('AmazonS3') || !Configure::read('Filebinder.S3.key') || !Configure::read('Filebinder.S3.secret')) {
                        //__('Validation Error: S3 Parameter Error');
                        @unlink($tmpFile);
                        return false;
                    }
                    $options = array('key' => Configure::read('Filebinder.S3.key'),
                        'secret' => Configure::read('Filebinder.S3.secret'),
                    );
                    $bucket = !empty($bindFields[$fieldName]['bucket']) ? $bindFields[$fieldName]['bucket'] : Configure::read('Filebinder.S3.bucket');
                    if (empty($bucket)) {
                        //__('Validation Error: S3 Parameter Error');
                        @unlink($tmpFile);
                        return false;
                    }
                    $s3 = new AmazonS3($options);
                    $region = !empty($bindFields[$fieldName]['region']) ? $bindFields[$fieldName]['region'] : Configure::read('Filebinder.S3.region');
                    if (!empty($region)) {
                        $s3->set_region($region);
                    }
                    $acl = !empty($bindFields[$fieldName]['acl']) ? $bindFields[$fieldName]['acl'] : Configure::read('Filebinder.S3.acl');
                    if (empty($acl)) {
                        $acl = AmazonS3::ACL_PUBLIC;
                    }
                    $urlPrefix = !empty($bindFields[$fieldName]['urlPrefix']) ? $bindFields[$fieldName]['urlPrefix'] : Configure::read('Filebinder.S3.urlPrefix');
                    $responce = $s3->create_object($bucket,
                        $urlPrefix . $model->transferTo(array_diff_key(array('model_id' => $model_id) + $value, Set::normalize(array('tmp_bind_path')))),
                        array(
                            'fileUpload' => $tmpFile,
                            'acl' => $acl,
                        ));
                    if (!$responce->isOK()) {
                        //__('Validation Error: S3 Upload Error');
                        @unlink($tmpFile);
                        return false;
                    }
                }
            }

            if ($filePath && file_exists($filePath) && is_file($filePath)) {

                /**
                 * afterAttach
                 */
                if (!empty($this->settings[$model->alias]['afterAttach'])) {
                    $res = $this->_userfunc($model, $this->settings[$model->alias]['afterAttach'], array($filePath));
                    if (!$res) {
                        @unlink($tmpFile);
                        return false;
                    }
                }

                /**
                 * afterSave
                 */
                if (!empty($this->settings[$model->alias]['afterSave'])) {
                    $res = $this->_userfunc($model, $this->settings[$model->alias]['afterSave'], array($tmpFile, $filePath, $bind));
                    if (!$res) {
                        @unlink($tmpFile);
                        return false;
                    }
                }
            }
            @unlink($tmpFile);
        }

    }

    /**
     * 保存処理
     */
    public function runtimeSave(Model $model, $bind)
    {
        $this->runtime[$model->alias]['bindedModel']->create();
        if (!$this->runtime[$model->alias]['bindedModel']->save($bind)) {
            return false;
        }
        return true;
    }
}
