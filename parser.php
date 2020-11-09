<?php

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

const OBTAIN_CONTENT = "
select uid, content, error_code, error_text
from receive_table 
where is_processed = false
order by is_processed, created_at
limit :unit_of_work_size
for update
";

const MARK_AS_PROCESSED = '
update receive_table
set is_processed = true
where uid = :uid
';

const DEFINE_MESSAGE_IDENTITY = '
update receive_table 
set request_id = :request_id,
    message_type = :message_type,
    updated_at = CURRENT_TIMESTAMP
where uid = :uid
';

const SET_MESSAGE_STATUS = '
update send_table 
set error_code = :error_code,
    error_text = :error_text,
    updated_at = CURRENT_TIMESTAMP    
where id = :id
';

const WRITE_ATTACHMENT = '
insert into message_attachment 
    (request_id, detail)
    VALUES (:request_id,:detail)
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

$markAsProcessed = $db->prepare(MARK_AS_PROCESSED);
if ($markAsProcessed !== false) {
    $markAsProcessed->bindParam(':uid', $uid);
}

$setStatus = $db->prepare(SET_MESSAGE_STATUS);
$errorCode = '';
$errorText = '';
if ($setStatus !== false) {
    $setStatus->bindParam(':error_code', $errorCode);
    $setStatus->bindParam(':error_text', $errorText);
    $setStatus->bindParam(':id', $replyToClientId);
}

$writeAttachment = $db->prepare(WRITE_ATTACHMENT);
$detailJson = '';
if ($writeAttachment !== false) {
    $writeAttachment->bindParam(':request_id', $replyToClientId);
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
    while ($reader->read()) {
        if ($isStatus && $catchId) {
            break;
        }

        $name = $reader->name;
        if ($name === 'messageType'
            && $reader->nodeType === XMLReader::ELEMENT) {
            $messageType = $reader->expand()->textContent;
            $isStatus = $messageType === 'StatusMessage';
            $isPrimary = $messageType === 'PrimaryMessage';

            continue;
        }
        if ($name === 'replyToClientId'
            && $reader->nodeType === XMLReader::ELEMENT) {
            $replyToClientId = $reader->expand()->textContent;
            $catchId = true;

            continue;
        }

        if ($isPrimary
            && $name === 'ns1:FNSLicUlResponse'
            && $reader->nodeType === XMLReader::ELEMENT) {
            $isLicense = true;

            $errorCode = $reader->getAttribute('КодОбр');
            $errorText = $reader->getAttribute('Ошибка');
        }
        if ($isPrimary
            && $name === 'tns:ZPVIPEGRResponse'
            && $reader->nodeType === XMLReader::ELEMENT) {
            $isFile = true;
        }
        if ($isFile
            && $name === 'fnst:ИмяФайла'
            && $reader->nodeType === XMLReader::ELEMENT) {
            $filename = $reader->expand()->textContent;
        }
        if ($isFile
            && $name === 'fnst:ТипФайла'
            && $reader->nodeType === XMLReader::ELEMENT) {
            $fileType = $reader->expand()->textContent;
        }
        if ($isFile
            && $name === 'fnst:ИмяАрхива'
            && $reader->nodeType === XMLReader::ELEMENT) {
            $archiveName = $reader->expand()->textContent;
        }
    }

    if (
        $catchId
        && $defineIdentity !== false
    ) {
        $uid = $record['uid'];
        $defineIdentity->execute();
    }
    if (
        $catchId
        && $isStatus
        && $setStatus !== false
    ) {
        $errorCode = $record['error_code'];
        $errorText = $record['error_text'];
        $setStatus->execute();
    }
    if (
        $catchId
        && $isFile
        && $writeAttachment !== false
    ) {
        $detailJson = json_encode(
            [
                'filename' => $filename,
                'fileType' => $fileType,
                'archiveName' => $archiveName,
            ]
        );
        $writeAttachment->execute();
    }
    if (
        $catchId
        && $isLicense
        && $writeLicense !== false
    ) {
        $writeLicense->execute();
    }

    if ($markAsProcessed !== false) {
        $uid = $record['uid'];
        $markAsProcessed->execute();
    }
}

$db->commit();

return;
