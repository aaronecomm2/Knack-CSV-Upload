<?php

define('CONFIGURATOR_PATH', 'config/config.ini');

require_once('utils/Configurator.php');
require_once('utils/Configuration.php');
require_once('utils/Logger.php');

Logger::init();

$config = Configurator::getInstance()->getConfig('app');

$mappings = file_get_contents($config->getMandatoryKey('mappings'));

define('APP_ID', $config->getMandatoryKey('appId'));
define('APP_KEY', $config->getMandatoryKey('appKey'));
define('OBJECT_ID', $config->getMandatoryKey('objectId'));

define('UPLOAD_DIR', $config->getKey('uploadDir', 'uploads'));
define('MAX_UPLOAD_SIZE', $config->getKey('maxFileSize', 1048576));

function mapObject($object, &$arr) {
    foreach($object as $key => &$value) {
        if(is_object($value)) {
            mapObject($value, $arr);
        } else {
            $value = $arr[$value - 1];
        }
    }
}

function read_hq_csv($file) {
    global $mappings;

    $records = array();
    $skipFirst = true;
    while($line = fgets($file)) {
        if($skipFirst) {
            $skipFirst = false;
            continue;
        }

        $row = str_getcsv(trim($line));
        if(is_array($row) && count($row)) {
            $object = json_decode($mappings);
            mapObject($object, $row);
            $records[] = $object;
        }
    }

    return $records;
}

if(isset($_GET['upload'])) { // Run file upload process
    Logger::info('upload started.');

    $paths = array();
    $errorMessage = null;
    $token = null;
    $targetName = null;
    try {
        if(empty($_FILES['files'])) {
            throw new Exception('No files found for upload.');
        }

        Logger::info('processing files.');

        $files = $_FILES['files'];
        $filenames = $files['name'];
        for($i = 0; $i < count($filenames); $i++) {
            $tmpName = $files['tmp_name'][$i];

            if(filesize($tmpName) > MAX_UPLOAD_SIZE) {
                throw new Exception('File exceeds maximum length.');
            }

            $token = md5(uniqid());
            $targetName = UPLOAD_DIR . DIRECTORY_SEPARATOR . $token;
            if(!move_uploaded_file($tmpName, $targetName)) {
                throw new Exception('Error while uploading files.');
            }

            $paths[] = $targetName;
        }

        Logger::info('finished.');

        echo json_encode(array(
            'token' => $token
        ));
    } catch(Exception $e) {
        Logger::info(sprintf('error: %s. removing files...', $e->getMessage()));

        foreach($paths as $file) {
            @unlink($file);
        }

        echo json_encode(array('error' => $e->getMessage()));
    }

    Logger::info('exit.');

    exit();
} else if(isset($_GET['process'])
        && isset($_GET['token'])
        && preg_match('/^[a-z0-9]+$/', $_GET['token'])
        && file_exists(UPLOAD_DIR . DIRECTORY_SEPARATOR . $_GET['token'])) { // Run API upload process
    Logger::info('processing file 2...');

    $token = $_GET['token'];
    $fileName = UPLOAD_DIR . DIRECTORY_SEPARATOR . $token;

    session_start();

    $errorsCount = 0;
    $errorMessage = null;
    $progress = 0;

    $_SESSION[$token] = array(
        'progress' => $progress
    );

    try {
        $file = @fopen($fileName, 'r');
        if(!$file) {
            throw new Exception('Error while uploading files.');
        }

        $records = read_hq_csv($file);

        @fclose($file);
        @unlink($fileName);

        Logger::info('csv processed.');
        Logger::debug(print_r($records, true));

        $url = sprintf('https://api.knackhq.com/v1/objects/object_%s/records', OBJECT_ID);
        $headers = array(
            'Content-Type: application/json',
            sprintf('X-Knack-Application-Id: %s', APP_ID),
            sprintf('X-Knack-REST-API-Key: %s', APP_KEY)
        );

        $_SESSION[$token]['rowsCount'] = count($records);
        $_SESSION[$token]['errorsCount'] = 0;
        if(0 === count($records)) {
            $_SESSION[$token]['progress'] = 100;
        }

        session_write_close();

        $recordsProcessed = 0;
        foreach($records as $record) {
            $encoded = json_encode($record);
            Logger::debug('encoded record: ' . $encoded);

            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, $url);
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($curl, CURLOPT_POSTFIELDS, $encoded);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
            curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

            $response = curl_exec($curl);
            Logger::debug('response: ' . $response);

            if($returnJson = json_decode($response)) {
                if(isset($returnJson->errors)) {
                    $errorMessage = $returnJson->errors[0];
                    $errorsCount++;
                }
            } else {
                $errorMessage = $response;
                $errorsCount++;
            }

            curl_close($curl);

            $recordsProcessed++;

            session_start();
            $_SESSION[$token]['progress'] = round(($recordsProcessed / count($records)) * 100);
            $_SESSION[$token]['errorsCount'] = $errorsCount;
            $_SESSION[$token]['error'] = $errorMessage;
            session_write_close();
        }

        echo json_encode(array());
    } catch(Exception $e) {
        echo json_encode(array('error' => 'Unknown error.'));
    }

    exit();
} else if(isset($_GET['state']) && isset($_GET['token'])) { // Return status
    session_start();

    $token = $_GET['token'];

    if(isset($_SESSION[$token])) {
        echo json_encode($_SESSION[$token]);
    } else {
        echo json_encode(array('progress' => 100));
    }

    exit();
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8" />

    <title>KnackHQ API CSV Upload Tool</title>

    <link rel="stylesheet" href="css/bootstrap.min.css" />
    <link rel="stylesheet" href="css/bootstrap-theme.min.css" />
    <link rel="stylesheet" href="css/fileinput.min.css" />

    <style type="text/css">
        #results label {
            color: #0C0 !important;
        }

        #recordsCount {
            color: #0E0 !important;
        }

        #errors label {
            color: #D00 !important;
        }

        #errorsCount {
            color: #F00 !important;
        }
    </style>

    <script type="text/javascript" src="js/jquery-2.1.4.min.js"></script>
    <script type="text/javascript" src="js/bootstrap-3.3.4.min.js"></script>
    <script type="text/javascript" src="js/fileinput-4.2.3.min.js"></script>
</head>

<body>
    <h3>KnackHQ API CSV Upload Tool</h3>
    <form action="?upload=1" method="post" role="form" class="form form-horizontal" enctype="multipart/form-data">
        <input type="hidden" id="MAX_FILE_SIZE" name="MAX_FILE_SIZE" value="<?php echo MAX_UPLOAD_SIZE ?>" />
        <div class="form-group">
            <label for="file" class="control-label col-sm-3">Select File To Upload:</label>
            <div class="col-sm-5">
                <input id="file" name="files[]" type="file" class="form-control file" data-show-preview="false" data-show-remove="false" data-show-upload="false" />
            </div>
        </div>
        <div class="form-group">
            <div id="errorBlock" class="col-sm-offset-3 col-sm-5 help-block">
            </div>
        </div>
        <div id="processBar" class="form-group">
            <label class="control-label col-sm-3">Processing...</label>
            <div class="col-sm-5">
                <div class="progress controls readonly">
                    <div class="progress-bar progress-bar-success progress-bar-striped active" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100" style="width:00%;">
                        0%
                    </div>
                </div>
            </div>
        </div>
        <div id="results" class="form-group">
            <label class="control-label col-sm-3">Records processed:</label>
            <div class="col-sm-5">
                <label id="recordsCount" class="control-label">
                </label>
            </div>
        </div>
        <div id="errors" class="form-group">
            <label class="control-label col-sm-3">Failed:</label>
            <div class="col-sm-5">
                <label id="errorsCount" class="control-label">
                </label>
            </div>
        </div>
        <div class="form-group">
            <div class="col-sm-offset-3 col-sm-5">
                <input id="btnUpload" type="submit" value="Upload" class="btn btn-default" />
            </div>
        </div>
    </form>

    <script type="text/javascript">
        (function($) {
            $('#processBar').hide();
            $('#results').hide();
            $('#errors').hide();

            $('#file').fileinput({
                // allowedFileExtensions: ['csv'],
                uploadUrl: '?upload=1',
                uploadAsync: false,
                maxFileSize: <?php echo round(MAX_UPLOAD_SIZE / 1024) ?>,
                elErrorContainer: "#errorBlock"
            });

            $('#file').on('filebatchuploadsuccess', function(evt, data) {
                var token = data.response.token;

                $('#processBar').show();
                $('#processBar .progress-bar').css('width', '0%').attr('aria-valuenow', 0).text('0%');

                $.ajax({
                    'url': '?process=1&token=' + token,
                    'method': 'POST',
                    'success': function(data) {
                        if(data.error) {
                            window.alert(data.error);
                        }
                    }
                });

                var checkStatus = function() {
                    $.ajax({
                        'url': '?state=1&token=' + token,
                        'method': 'GET',
                        'dataType': 'json',
                        'success': function(data) {
                            console.log(data);

                            if(data && (typeof data.progress != 'undefined')) {
                                $('#processBar .progress-bar').css('width', '' + data.progress + '%').attr('aria-valuenow', data.progress).text(data.progress + '%');

                                if(parseInt(data.progress) < 100) {
                                    setTimeout(checkStatus, 1000);
                                } else {
                                    $('#processBar').hide();
                                    if(data.errorsCount) {
                                        $('#errors').show();
                                        $('#errorsCount').text(data.error + ' (' + data.errorsCount + ' errors)');
                                    } else {
                                        $('#results').show();
                                        $('#recordsCount').text(data.rowsCount);
                                    }
                                }
                            } else {
                                window.alert('something wrong');
                            }
                        }
                    });
                }

                setTimeout(checkStatus, 1000);
            });

            $('#btnUpload').click(function() {
                $('#processBar').hide();
                $('#results').hide();
                $('#errors').hide();

                $('#file').fileinput('upload');

                return false;
            });
        })(jQuery);
    </script>
</body>

</html>
