<?php
App::uses('BindableBehavior', 'Filebinder.Model/Behavior');

class ImageBindableBehavior extends BindableBehavior {

    protected $orgData;

    /**
     * beforeSave
     *
     * @param $model
     * @return
     */
    public function beforeSave(Model $model) {
        $modelName = $model->alias;
        $this->orgData = $model->data;
        $model->bindedData = $model->data;
        foreach ($model->data[$modelName] as $fieldName => $value) {
            if (!in_array($fieldName, Set::extract('/field', $model->bindFields))) {
                continue;
            }

            if (empty($value) || empty($value['tmp_bind_path'])) {
                unset($model->data[$modelName][$fieldName]);
                continue;
            }

            if (empty($value['file_size'])) {
                $model->data[$modelName][$fieldName] = null;
                $model->invalidate($fieldName, __('Validation Error: File Upload Error', true));
                return false;
            }

            $bind = $value;
            $bind['model'] = $modelName;
            $bind['model_id'] = 0;

            $tmpFile = $value['tmp_bind_path'];
            if (file_exists($tmpFile) && is_file($tmpFile)) {
                /**
                 * beforeAttach
                 */
                if (!empty($this->settings[$model->alias]['beforeAttach'])) {
                    $res = $this->_userfunc($model, $this->settings[$model->alias]['beforeAttach'], array($tmpFile));

                    // ここからImagebinder独自処理
                    // beforeAttachで変換した画像情報で上書く処理
                    if (is_array($res)) {
                        if (!$res['result']) {
                            return false;
                        }

                        if (isset($res['tmp_bind_path'])) {
                            $tmpFile = $res['tmp_bind_path'];
                            $bind['tmp_bind_path'] = $tmpFile;
                            $model->bindedData[$modelName][$fieldName]['tmp_bind_path'] = $tmpFile;
                        }
                        if (isset($res['extention'])) {
                            $tmpData = pathinfo($bind['file_name']);
                            $bind['file_name'] = $tmpData['filename'] .
                                '.' . $res['extention'];
                            $model->bindedData[$modelName][$fieldName]['file_name'] = $bind['file_name'];
                            $tmpData = null;
                        }
                        if (isset($res['file_size'])) {
                            $bind['file_size'] = $res['file_size'];
                            $model->bindedData[$modelName][$fieldName]['file_size'] = $res['file_size'];
                        }
                        if (isset($res['file_content_type'])) {
                            $bind['file_content_type'] = $res['file_content_type'];
                            $model->bindedData[$modelName][$fieldName]['file_content_type'] = $res['file_content_type'];
                        }

                    } else if (!$res) {
                        return false;
                    }
                    // ここまでImagebinder独自処理
                }

                /**
                 * Database storage
                 */
                $dbStorage = in_array(BindableBehavior::STORAGE_DB, (array)$this->settings[$model->alias]['storage']);
                // backward compatible
                if (isset($this->settings[$model->alias]['dbStorage'])) {
                    $dbStorage = $this->settings[$model->alias]['dbStorage'];
                }
                if ($dbStorage) {
                    $bind['file_object'] = base64_encode(file_get_contents($tmpFile));
                }
            }

            $this->runtime[$model->alias]['bindedModel']->create();
            if (!$data = $this->runtime[$model->alias]['bindedModel']->save($bind)) {
                return false;
            }

            $bind_id = $this->runtime[$model->alias]['bindedModel']->getLastInsertId();
            unset($model->data[$modelName][$fieldName]);
            $model->bindedData[$modelName][$fieldName] = $data[$this->runtime[$model->alias]['bindedModel']->alias] + array($this->runtime[$model->alias]['bindedModel']->primaryKey => $bind_id);
        }

        return true;
    }

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
            }
            @unlink($tmpFile);
        }

        // ここからImagebinder独自処理
        // 元ファイルも保存
        foreach ($this->orgData[$modelName] as $fieldName => $value) {
            if (!in_array($fieldName, Set::extract('/field', $model->bindFields))) {
                continue;
            }
            $tmpFile = $value['tmp_bind_path'];
            if (file_exists($tmpFile) && is_file($tmpFile)) {

                if ($filePath) {
                    $currentMask = umask();
                    umask(0);
                    if (!is_dir(dirname($filePath))) {
                        mkdir(dirname($filePath), $this->settings[$model->alias]['dirMode'], true);
                    }


                    $fileData = pathinfo($tmpFile);

                    $fileName = Configure::read('Imagebinder.originalFilename')
                        ? Configure::read('Imagebinder.originalFilename') . '.' . $fileData['extension']
                        : $value['file_name'];
                    $targetFile = dirname($filePath) . DS .  $fileName;
                    if (!copy($tmpFile, $targetFile) || !chmod($targetFile, $this->settings[$model->alias]['fileMode'])) {
                        @unlink($tmpFile);
                        umask($currentMask);
                        return false;
                    }
                    umask($currentMask);
                }
                @unlink($tmpFile);
            }
        }
        // ここまでImagebinder独自処理
    }
}
