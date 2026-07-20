<?php
class FileUploader {
    private $uploadDir;
    private $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png', 'docx', 'doc'];
    private $allowedMimes = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/msword'
    ];
    private $maxSize = 10485760; // 10MB

    public function __construct($uploadDir = __DIR__ . '/../uploads/') {
        $this->uploadDir = $uploadDir;
        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0755, true);
        }
    }

    public function handleUpload($fileArray, $fieldName, $applicantName, $formSlug) {
        if (!isset($fileArray['error']) || is_array($fileArray['error'])) {
            throw new Exception("Invalid file structure for field: $fieldName");
        }

        switch ($fileArray['error']) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_NO_FILE:
                return null; // Optional fields won't upload
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                throw new Exception("File exceeded maximum size (10MB).");
            default:
                throw new Exception("Unknown upload error.");
        }

        if ($fileArray['size'] > $this->maxSize) {
            throw new Exception("File exceeded maximum size (10MB).");
        }

        // Check extension
        $ext = strtolower(pathinfo($fileArray['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $this->allowedExtensions)) {
            throw new Exception("Invalid file extension. Allowed: " . implode(', ', $this->allowedExtensions));
        }

        // Check double extensions (e.g. file.php.pdf)
        $parts = explode('.', $fileArray['name']);
        if (count($parts) > 2) {
            throw new Exception("Invalid file name format (multiple extensions detected).");
        }

        // Check MIME type using finfo
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($fileArray['tmp_name']);
        if ($mime === false || !in_array($mime, $this->allowedMimes)) {
            throw new Exception("Invalid file format. Detected MIME type is not allowed.");
        }

        // Sanitize applicant name for filename
        $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower($applicantName));
        $safeSlug = preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower($formSlug));
        $timestamp = time();

        $newFileName = sprintf('%s_%s_%s_%s.%s', $safeSlug, $safeName, $fieldName, $timestamp, $ext);
        $destination = $this->uploadDir . $newFileName;

        if (!move_uploaded_file($fileArray['tmp_name'], $destination)) {
            throw new Exception("Failed to move uploaded file.");
        }

        return 'uploads/' . $newFileName;
    }
}
