<?php
// Диагностический скрипт для проверки индекса book_zip
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once('/application/dbinit.php');

if (!isset($argv[1])) {
    echo "Использование: php debug_book_zip.php <book_id>\n";
    exit(1);
}

$book_id = (int)$argv[1];

echo "=== Диагностика для книги ID: $book_id ===\n\n";

// 1. Проверяем информацию о книге
echo "1. Информация о книге:\n";
$stmt = $dbh->prepare("SELECT BookId, Title, FileType FROM libbook WHERE BookId = :id");
$stmt->bindParam(":id", $book_id, PDO::PARAM_INT);
$stmt->execute();
$book = $stmt->fetch(PDO::FETCH_ASSOC);
if ($book) {
    echo "   BookId: {$book['BookId']}\n";
    echo "   Title: {$book['Title']}\n";
    echo "   FileType: {$book['FileType']}\n";
    $usr = (strtolower(trim($book['FileType'])) == 'fb2') ? 0 : 1;
    echo "   Определенный usr: $usr (0=FB2, 1=другие)\n";
} else {
    echo "   Книга не найдена в базе данных!\n";
    exit(1);
}

echo "\n2. Поиск в book_zip:\n";
// 2. Ищем записи в book_zip для этого ID
$stmt = $dbh->prepare("SELECT * FROM book_zip WHERE :id BETWEEN start_id AND end_id");
$stmt->bindParam(":id", $book_id, PDO::PARAM_INT);
$stmt->execute();
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($results)) {
    echo "   ❌ Записей не найдено для ID $book_id\n";
    
    // Ищем ближайшие записи
    echo "\n3. Ближайшие записи в book_zip:\n";
    $stmt = $dbh->prepare("SELECT * FROM book_zip WHERE start_id <= :id ORDER BY start_id DESC LIMIT 5");
    $stmt->bindParam(":id", $book_id, PDO::PARAM_INT);
    $stmt->execute();
    $before = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $dbh->prepare("SELECT * FROM book_zip WHERE start_id > :id ORDER BY start_id ASC LIMIT 5");
    $stmt->bindParam(":id", $book_id, PDO::PARAM_INT);
    $stmt->execute();
    $after = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "   Записи до ID $book_id:\n";
    foreach ($before as $row) {
        echo "     filename: {$row['filename']}, start_id: {$row['start_id']}, end_id: {$row['end_id']}, usr: {$row['usr']}\n";
    }
    
    echo "   Записи после ID $book_id:\n";
    foreach ($after as $row) {
        echo "     filename: {$row['filename']}, start_id: {$row['start_id']}, end_id: {$row['end_id']}, usr: {$row['usr']}\n";
    }
} else {
    echo "   ✅ Найдено записей: " . count($results) . "\n";
    foreach ($results as $row) {
        echo "     filename: {$row['filename']}, start_id: {$row['start_id']}, end_id: {$row['end_id']}, usr: {$row['usr']}\n";
        
        // Проверяем, соответствует ли usr
        if ($row['usr'] != $usr) {
            echo "     ⚠️  ВНИМАНИЕ: usr не совпадает! Ожидается: $usr, в базе: {$row['usr']}\n";
        }
    }
}

echo "\n4. Поиск с учетом usr:\n";
$stmt = $dbh->prepare("SELECT * FROM book_zip WHERE :id BETWEEN start_id AND end_id AND usr = :usr");
$stmt->bindParam(":id", $book_id, PDO::PARAM_INT);
$stmt->bindParam(":usr", $usr, PDO::PARAM_INT);
$stmt->execute();
$results_with_usr = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($results_with_usr)) {
    echo "   ❌ Записей не найдено для ID $book_id с usr=$usr\n";
} else {
    echo "   ✅ Найдено записей: " . count($results_with_usr) . "\n";
    foreach ($results_with_usr as $row) {
        echo "     filename: {$row['filename']}, start_id: {$row['start_id']}, end_id: {$row['end_id']}, usr: {$row['usr']}\n";
    }
}

echo "\n5. Статистика book_zip:\n";
$stmt = $dbh->query("SELECT 
    COUNT(*) as total,
    MIN(start_id) as min_start,
    MAX(end_id) as max_end,
    COUNT(DISTINCT usr) as usr_types
FROM book_zip");
$stats = $stmt->fetch(PDO::FETCH_ASSOC);
echo "   Всего записей: {$stats['total']}\n";
echo "   Минимальный start_id: {$stats['min_start']}\n";
echo "   Максимальный end_id: {$stats['max_end']}\n";
echo "   Типов usr: {$stats['usr_types']}\n";

echo "\n=== Конец диагностики ===\n";
