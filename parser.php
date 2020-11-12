<?php

const CODE_403_REJECT = 403;
const CODE_500_FAIL = 500;
const CODE_200_OK = 200;
const MESSAGE_200_OK = 'OK';

$host = getenv('DB_HOST');
$baseName = getenv('DB_NAME');
$login = getenv('DB_USER');
$password = getenv('DB_PASSWORD');

if ($host === false
    || $baseName === false
    || $login === false
    || $password === false
) {
    throw new Exception('Database requisites not sufficient');
}

$unitOfWorkSize = getenv('DB_UNIT_OF_WORK_SIZE');
if ($unitOfWorkSize === false) {
    $unitOfWorkSize = 100;
}

const OBTAIN_CONTENT = '
select uid, content
from receive_table 
where is_processed = false
order by is_processed, created_at
limit :unit_of_work_size
for update
';

const MARK_AS_PROCESSED = '
update receive_table
set is_processed = true,
    error_code = :result_code,
    error_text = :result_message,
    updated_at = CURRENT_TIMESTAMP
where is_processed = false and uid = :uid
';

const DEFINE_MESSAGE_IDENTITY = '
update receive_table 
set request_id = :request_id,
    message_type = :message_type,
    updated_at = CURRENT_TIMESTAMP
where is_processed = false and uid = :uid
';

const SET_MESSAGE_STATUS = '
update send_table 
set error_code = :error_code,
    error_text = :error_text,
    updated_at = CURRENT_TIMESTAMP    
where id = :id
';

const WRITE_ATTACHMENT = '
insert into smev_receive_message_attachment 
    (receive_table_uid, filename,detail,created_at)
    VALUES (:uid,:filename,:detail,CURRENT_TIMESTAMP)
';

const WRITE_LICENSE = '
update send_table 
set error_code = :error_code,
    error_text = :error_text,
    is_complete = true,
    updated_at = CURRENT_TIMESTAMP
where id = :id
';

$db = new PDO(
    "pgsql:host=$host;dbname=$baseName;",
    $login,
    $password
);

$cursor = $db->prepare(OBTAIN_CONTENT);
if ($cursor !== false) {
    $cursor->bindParam(
        ':unit_of_work_size',
        $unitOfWorkSize,
        PDO::PARAM_INT
    );
}

$defineIdentity = $db->prepare(DEFINE_MESSAGE_IDENTITY);
$replyToClientId = '';
$messageType = '';
$uid = '';
if ($defineIdentity !== false) {
    $defineIdentity->bindParam(':request_id', $replyToClientId);
    $defineIdentity->bindParam(':message_type', $messageType);
    $defineIdentity->bindParam(':uid', $uid);
}

$resultCode = CODE_200_OK;
$resultMessage = MESSAGE_200_OK;
$markAsProcessed = $db->prepare(MARK_AS_PROCESSED);
if ($markAsProcessed !== false) {
    $markAsProcessed->bindParam(
        ':result_code',
        $resultCode,
        PDO::PARAM_INT
    );
    $markAsProcessed->bindParam(':result_message', $resultMessage);
    $markAsProcessed->bindParam(':uid', $uid);
}

$setStatus = $db->prepare(SET_MESSAGE_STATUS);
$errorCode = 0;
$errorText = '';
if ($setStatus !== false) {
    $setStatus->bindParam(':error_code', $errorCode);
    $setStatus->bindParam(':error_text', $errorText);
    $setStatus->bindParam(':id', $replyToClientId);
}

$writeAttachment = $db->prepare(WRITE_ATTACHMENT);
$detailJson = '';
$archiveName = '';
if ($writeAttachment !== false) {
    $writeAttachment->bindParam(':uid', $uid);
    $writeAttachment->bindParam(':filename', $archiveName);
    $writeAttachment->bindParam(':detail', $detailJson);
}
$writeLicense = $db->prepare(WRITE_LICENSE);
if ($writeLicense !== false) {
    $writeLicense->bindParam(':error_code', $errorCode);
    $writeLicense->bindParam(':error_text', $errorText);
    $writeLicense->bindParam(':id', $replyToClientId);
}

$db->beginTransaction();

if ($cursor !== false && $markAsProcessed !== false) {
    $cursor->execute();
}
while ($cursor && $record = $cursor->fetch(PDO::FETCH_ASSOC)) {
    $content = $record['content'];

    try {
        $reader = (new XMLReader());
        $reader->XML($content);

        $messageType = '';
        $replyToClientId = '';
        $catchId = false;
        $isStatus = false;
        $isPrimary = false;
        $isFile = false;
        $isLicense = false;
        $filename = '';
        $fileType = '';
        $archiveName = '';
        $detailJson = '';
        $messageId = '';
        $catchMessageId = false;
        $isReject = false;
        $errorCode = 0;
        $errorText = '';
        $catchCode = false;
        $catchText = false;
        $catchFilename = true;
        $catchFileType = true;
        $catchArchiveName = true;
        $statusCode = '';
        while ($reader->read()) {
            if ($isStatus && $catchId && $catchCode && $catchText) {
                break;
            }
            if ($isFile
                && $catchId
                && $catchFilename
                && $catchFileType
                && $catchArchiveName
                && $catchMessageId
            ) {
                break;
            }
            if ($isLicense && $catchId) {
                break;
            }

            $name = $reader->name;
            $isElement = $reader->nodeType === XMLReader::ELEMENT;

            if ($name === 'MessageId' && $isElement) {
                $messageId = $reader->expand()->textContent;
                $catchMessageId = true;
            }
            if ($name === 'messageType' && $isElement) {
                $messageType = $reader->expand()->textContent;
                $isStatus = $messageType === 'StatusMessage'
                    || $messageType === 'RejectMessage';
                $isPrimary = $messageType === 'PrimaryMessage';
            }
            if ($isStatus
                && $name === 'code'
                && $isElement) {
                $statusCode = $reader->expand()->textContent;
                $errorCode = (int)$statusCode;

                $catchCode = true;
            }
            if ($isStatus
                && $name === 'description'
                && $isElement) {
                $errorText = $reader->expand()->textContent;
                $catchText = true;
            }
            if ($name === 'replyToClientId' && $isElement) {
                $replyToClientId = $reader->expand()->textContent;
                $catchId = true;
            }

            $isLicTag = in_array(
                $name,
                ['ns1:FNSLicUlResponse', 'ns1:FNSLicIPResponse'],
                true
            );
            if ($isPrimary && $isLicTag && $isElement) {
                $isLicense = true;

                $errorCode = (int)$reader->getAttribute('КодОбр');
                $errorText = $reader->getAttribute('Ошибка');
            }
            if ($isPrimary
                && $name === 'tns:ZPVIPEGRResponse'
                && $isElement) {
                $isFile = true;
            }
            if ($isFile && $name === 'fnst:ИмяФайла' && $isElement) {
                $filename = $reader->expand()->textContent;
                $catchFilename = true;
            }
            if ($isFile && $name === 'fnst:ТипФайла' && $isElement) {
                $fileType = $reader->expand()->textContent;
                $catchFileType = true;
            }
            if ($isFile && $name === 'fnst:ИмяАрхива' && $isElement) {
                $archiveName = $reader->expand()->textContent;
                $catchArchiveName = true;
            }
        }

        if ($catchId && $defineIdentity !== false) {
            $uid = $record['uid'];
            $defineIdentity->execute();
        }
        $letDefineAsReject = true;
        if ($isStatus
            && $errorCode === 0
        ) {
            $letDefineAsReject = !is_numeric($statusCode);
        }
        if ($letDefineAsReject) {
            $errorCode = CODE_403_REJECT;
        }
        if ($isStatus && $catchId && $setStatus !== false) {
            $setStatus->execute();
        }
        if ($isFile && $catchMessageId && $writeAttachment !== false) {
            $detailJson = json_encode(
                [
                    'path' => "in/$messageId/$archiveName",
                    'filename' => $filename,
                    'fileType' => $fileType,
                    'archiveName' => $archiveName,
                ]
            );
            $uid = $record['uid'];
            $writeAttachment->execute();
        }
        if ($isLicense && $catchId && $writeLicense !== false) {
            $writeLicense->execute();
        }

        $resultCode = CODE_200_OK;
        $resultMessage = MESSAGE_200_OK;
    } catch (Throwable $e) {
        $resultCode = CODE_500_FAIL;
        $resultMessage = json_encode(
            [
                'Message' => $e->getMessage(),
                'Code' => $e->getCode(),
                'Trace' => $e->getTrace(),
            ]
        );
    }

    $uid = $record['uid'];
    $markAsProcessed->execute();
}

$db->commit();

return;
