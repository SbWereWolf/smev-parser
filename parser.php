<?php

const OBTAIN_CONTENT = '
select uid, content, error_code, error_text
from receive_table 
where is_processed = false 
limit 1000
';

const DEFINE_MESSAGE_IDENTITY = '
update receive_table 
set request_id = :request_id,
    message_type = :message_type    
where uid = :uid
';

const SET_MESSAGE_STATUS = '
update send_table 
set error_code = :error_code,
    error_text = :error_text    
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
    is_complete = true
where id = :id
';

$db = new PDO(
    'pgsql:host=localhost;dbname=rpn_smev;',
    'postgres',
    'root'
);
$defineIdentity = $db->prepare(DEFINE_MESSAGE_IDENTITY);
$replyToClientId = '';
$messageType = '';
$uid = '';
if ($defineIdentity !== false) {
    $defineIdentity->bindParam(':request_id', $replyToClientId);
    $defineIdentity->bindParam(':message_type', $messageType);
    $defineIdentity->bindParam(':uid', $uid);
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
if ($setStatus !== false) {
    $setStatus->bindParam(':error_code', $errorCode);
    $setStatus->bindParam(':error_text', $errorText);
    $setStatus->bindParam(':id', $replyToClientId);
}

$db->beginTransaction();

$cursor = $db->query(OBTAIN_CONTENT);
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
}

$db->commit();

return;
