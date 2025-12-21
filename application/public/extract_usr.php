<?php
include('../init.php');

if (isset($_GET['id'])) {
	$id = $_GET['id'];
} else {
	$id = 610095;
}

$book = $dbh->prepare("SELECT libbook.Title BookTitle, libbook.FileType, libfilename.filename,
	CONCAT(libavtorname.LastName, ' ', libavtorname.FirstName) author_name
		FROM libbook 
		LEFT JOIN libavtor USING(BookId) 
		LEFT JOIN libavtorname USING(AvtorId) 
		LEFT JOIN libfilename USING(BookId) 
		WHERE libbook.BookId=:id");
$book->bindParam(":id", $id, PDO::PARAM_INT);
$book->execute();
$book = $book->fetchObject();

$usr_filename = $dbh->prepare("SELECT * FROM libfilename where BookId=:id");
$usr_filename->bindParam(":id", $id, PDO::PARAM_INT);
$usr_filename->execute();
$usr_filename = $usr_filename->fetchObject();


if ($usr_filename == '') {
	$usr_filename = trim("$id.$book->filetype");
}

$zip_stmt = $dbh->prepare("SELECT * FROM book_zip WHERE (:id BETWEEN start_id AND end_id) AND usr=1");
$zip_stmt->bindParam(":id", $id, PDO::PARAM_INT);
$zip_stmt->execute();
$zip_name = $zip_stmt->fetchObject()->filename;
$zip = new ZipArchive(); 

if ($zip->open("/work/fb/Flibusta.Net/" . $zip_name)) {
	$filename = $book->author_name . " - " . $book->booktitle . " " . $book->filename . "." . trim($book->filetype);

	header('Content-Description: File Transfer');
	header('Content-Type: application/octet-stream');
	header('Content-Transfer-Encoding: binary');
	header('Expires: 0');
	header('Cache-Control: must-revalidate');
	header('Pragma: public');
	header('Content-Disposition: attachment; filename=' . basename(rawurlencode($filename)));

	$data = $zip->getFromName($usr_filename);
	if ($data == '') {
		$data = $zip->getFromName($usr_filename . ".zip");
	}

	echo $data;
	$zip->close();
} else {
	echo "NO ZIP";
}


