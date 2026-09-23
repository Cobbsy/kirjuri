<?php
// Case attachments, stored gzip-compressed in the attachments table (request_id is the case UID).

/** An attachment by ID, without its content unless $with_content is set, or null. */
function kirjuri_find_attachment(PDO $db, $id, $with_content = false) {
    $query = $db->prepare('SELECT id, request_id, name, description, type, size, uploader, date_uploaded, hash, attr_1'
        . ($with_content ? ', content' : '') . ' FROM attachments WHERE id = :id');
    $query->execute(array(':id' => $id));
    $file = $query->fetch(PDO::FETCH_ASSOC);
    return $file === false ? null : $file;
}


/** The attachments of a case; with $with_content, every column including the compressed content. */
function kirjuri_case_attachments(PDO $db, $case_id, $with_content = false) {
    $query = $db->prepare('SELECT ' . ($with_content ? '*' : 'id, name, size, uploader, type') . ' FROM attachments WHERE request_id = :id ORDER BY id');
    $query->execute(array(':id' => $case_id));
    return $query->fetchAll(PDO::FETCH_ASSOC);
}


/** UIDs of cases that have attachments. */
function kirjuri_cases_with_attachments(PDO $db) {
    return $db->query('SELECT DISTINCT request_id FROM attachments')->fetchAll(PDO::FETCH_COLUMN);
}


function kirjuri_attachment_exists(PDO $db, $case_id, $sha256) {
    $query = $db->prepare('SELECT COUNT(*) FROM attachments WHERE hash = :hash AND request_id = :id');
    $query->execute(array(':hash' => $sha256, ':id' => $case_id));
    return (int) $query->fetchColumn() > 0;
}


/**
 * Store an attachment. $content is the uncompressed file; $audit_stamp the audit log entry for the
 * upload, if already written. Returns array('id' => ..., 'stored_size' => compressed size).
 */
function kirjuri_add_attachment(PDO $db, $case_id, $name, $type, $content, $uploader, $audit_stamp = null) {
    $query = $db->prepare('INSERT INTO attachments (name, request_id, type, size, content, uploader, hash, date_uploaded, attr_1)
        VALUES (:name, :request_id, :type, :size, :content, :uploader, :hash, NOW(), :attr_1)');
    $compressed = gzencode($content);
    $query->execute(array(
            ':name' => $name,
            ':request_id' => $case_id,
            ':type' => $type,
            ':size' => strlen($content),
            ':content' => $compressed,
            ':uploader' => $uploader,
            ':hash' => hash('sha256', $content),
            ':attr_1' => $audit_stamp,
        ));
    return array('id' => $db->lastInsertId(), 'stored_size' => strlen($compressed));
}


function kirjuri_set_attachment_audit_stamp(PDO $db, $id, $audit_stamp) {
    $db->prepare('UPDATE attachments SET attr_1 = :stamp WHERE id = :id')->execute(array(':stamp' => $audit_stamp, ':id' => $id));
}


function kirjuri_delete_attachment(PDO $db, $id) {
    $db->prepare('DELETE FROM attachments WHERE id = :id')->execute(array(':id' => $id));
}


/** Insert an attachment row from a KRF export as is (content already compressed). $row keys must be validated. */
function kirjuri_import_attachment(PDO $db, $case_id, array $row) {
    unset($row['id'], $row['attr_1']);
    $row['request_id'] = $case_id;
    foreach (array_keys($row) as $column) {
        if (!preg_match('/^[a-z0-9_]+$/', $column)) {
            throw new InvalidArgumentException('Invalid column name: ' . $column);
        }
    }
    $columns = array_keys($row);
    $query = $db->prepare('INSERT INTO attachments (' . implode(', ', $columns) . ') VALUES (:' . implode(', :', $columns) . ')');
    $values = array();
    foreach ($row as $column => $value) {
        $values[':' . $column] = $value;
    }
    $query->execute($values);
}
