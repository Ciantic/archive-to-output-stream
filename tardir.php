<?PHP 
/**
 * @author Jari Pennanen
 * @license MIT License
 *
 * Tar balls a current directory and sends to php://output
 * 
 * If given $_GET["dir"] it only tarballs that directory
 */

$allow_root_tar = true;

if (empty($_GET["dir"])) {
    if (!$allow_root_tar) {
        die("give ?dir=DIRECTORY_NAME_HERE");
    }
    $the_directory = ".";
    // Some fake filename from the domain and url
    $fake_file_name = str_replace("_tardir_php", "", 
        preg_replace("#[^a-zA-Z0-9]#", "_", 
            "{$_SERVER['HTTP_HOST']}{$_SERVER['REQUEST_URI']}") . ".tar"
    );
} else {
    // Sanitize . and / and \ away
    $the_directory = preg_replace("#[./\\\]*#", "", $_GET["dir"]);
    $fake_file_name = $_GET["dir"] . '.tar';
    if (!$the_directory || !file_exists($the_directory)) {
        die("Directory '$the_directory' not found or given in incorrect format");
    }
}

// Open the directory for recursive iteration
$dirIterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($the_directory, FilesystemIterator::SKIP_DOTS), 
    RecursiveIteratorIterator::SELF_FIRST
);

// Open the tar ball
header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename=' . $fake_file_name);
$tar = new Tar("php://output");

foreach ($dirIterator as $path => $splFileInfo) {
    if ($splFileInfo->isFile()) {
        // Remove "." directory from tar ball when using root tars
        if ($the_directory === "." && strpos($path, "./") === 0) {
            $path = substr($path, 2);
        }
        // Add file to tarball
        $tar->addFile($path, $splFileInfo);
    }
}

$tar->close();

return;

/**
 * Tar ball class
 *
 * @author Jari Pennanen
 * @license MIT License
 *
 * Basically stripped the TAR parts from a Andreas Gohr's PHP Archive:
 * https://github.com/splitbrain/php-archive and made to work directly on
 * php://output so that it does not need to use memory besides the buffer size
 */
class Tar {
    protected $file = '';
    protected $fh;
    protected $closed = true;
    protected $writeaccess = false;
    
    /**
     * @param string $file
     */
    public function __construct($file = '')
    {
        $this->file   = $file;
        $this->fh     = 0;
        if ($this->file) {
            $this->fh = @fopen($this->file, 'wb');
            if (!$this->fh) {
                throw new Exception('Could not open file for writing: '.$this->file);
            }
        }
        $this->writeaccess = true;
        $this->closed      = false;
    }
    
    /**
     * Add a file to the current TAR archive using an existing file in the
     * filesystem
     *
     * @param string $file path to the original file (and the name of the tar
     * file)
     * @param string|SplFileInfo $splFileinfo either the name to us in archive
     * (string) or a FileInfo oject with all meta data, empty to take from
     * original
     */
    public function addFile($file, $splFileinfo = '')
    {
        if (is_string($splFileinfo)) {
            $splFileinfo = new SplFileInfo($file);
        }
        if ($this->closed) {
            throw new Exception('Archive has been closed, files can no longer be added');
        }
        $fp = @fopen($splFileinfo->getPathname(), 'rb');
        if (!$fp) {
            throw new Exception('Could not open file for reading: ' . $splFileinfo->getPathname());
        }

        // create file header
        $this->writeFileHeader($file, $splFileinfo);

        // write data
        $read = 0;
        while (!feof($fp)) {
            $data = fread($fp, 512);
            $read += strlen($data);
            if ($data === false) {
                break;
            }
            if ($data === '') {
                break;
            }
            $packed = pack("a512", $data);
            $this->writebytes($packed);
        }
        fclose($fp);
        
        if ($read != $splFileinfo->getSize()) {
            $this->close();
            throw new Exception("The size of $file changed while reading" . 
                ", archive corrupted. read $read expected ".$splFileinfo->getSize());
        }
    }

    /**
     * Add a file to the current TAR archive using the given $data as content
     *
     * @param string $data binary content of the file to add
     * @param int $uid
     * @param int $gid
     * @param int $perm
     * @param int $size
     * @param int $mtime
     * @throws ArchiveIOException
     */
    public function addData($data, $name, $uid, $gid, $perm, $mtime, $typeflag)
    {
        if ($this->closed) {
            throw new Exception('Archive has been closed, files can no longer be added');
        }
        $size = strlen($data);
        $this->writeRawFileHeader($name, $uid, $gid, $perm, $size, $mtime, $typeflag);
        for ($s = 0; $s < $len; $s += 512) {
            $this->writebytes(pack("a512", substr($data, $s, 512)));
        }
    }

    public function close()
    {
        if ($this->closed) {
            return;
        } // we did this already
        // write footer
        if ($this->writeaccess) {
            $this->writebytes(pack("a512", ""));
            $this->writebytes(pack("a512", ""));
        }
        // close file handles
        if ($this->file) {
            fclose($this->fh);
            $this->file = '';
            $this->fh   = 0;
        }
        $this->writeaccess = false;
        $this->closed      = true;
    }
    
    /**
     * Write to the open filepointer or memory
     *
     * @param string $data
     * @return int number of bytes written
     */
    protected function writebytes($data)
    {
        $written = @fwrite($this->fh, $data);
        if ($written === false) {
            throw new Exception('Failed to write to archive stream');
        }
        return $written;
    }
   
    /**
     * Write the given file meta data as header
     *
     * @param string $filePath
     * @param SplFileInfo $fileinfo
     * @throws ArchiveIOException
     */
    protected function writeFileHeader($filePath, SplFileInfo $fileinfo)
    {
        $this->writeRawFileHeader(
            $filePath,
            $fileinfo->getOwner(),
            $fileinfo->getGroup(),
            $fileinfo->getPerms(),
            $fileinfo->getSize(),
            $fileinfo->getMTime(),
            $fileinfo->isDir() ? '5' : '0'
        );
    }
    /**
     * Write a file header to the stream
     *
     * @param string $name
     * @param int $uid
     * @param int $gid
     * @param int $perm
     * @param int $size
     * @param int $mtime
     * @param string $typeflag Set to '5' for directories
     * @throws ArchiveIOException
     */
    protected function writeRawFileHeader($name, $uid, $gid, $perm, $size, $mtime, $typeflag = '')
    {
        // handle filename length restrictions
        $prefix  = '';
        $namelen = strlen($name);
        if ($namelen > 100) {
            $file = basename($name);
            $dir  = dirname($name);
            if (strlen($file) > 100 || strlen($dir) > 155) {
                // we're still too large, let's use GNU longlink
                $this->writeRawFileHeader('././@LongLink', 0, 0, 0, $namelen, 0, 'L');
                for ($s = 0; $s < $namelen; $s += 512) {
                    $this->writebytes(pack("a512", substr($name, $s, 512)));
                }
                $name = substr($name, 0, 100); // cut off name
            } else {
                // we're fine when splitting, use POSIX ustar
                $prefix = $dir;
                $name   = $file;
            }
        }
        // values are needed in octal
        $uid   = sprintf("%6s ", decoct($uid));
        $gid   = sprintf("%6s ", decoct($gid));
        $perm  = sprintf("%6s ", decoct($perm));
        $size  = sprintf("%11s ", decoct($size));
        $mtime = sprintf("%11s", decoct($mtime));
        $data_first = pack("a100a8a8a8a12A12", $name, $perm, $uid, $gid, $size, $mtime);
        $data_last  = pack("a1a100a6a2a32a32a8a8a155a12", $typeflag, '', 'ustar', '', '', '', '', '', $prefix, "");
        for ($i = 0, $chks = 0; $i < 148; $i++) {
            $chks += ord($data_first[$i]);
        }
        for ($i = 156, $chks += 256, $j = 0; $i < 512; $i++, $j++) {
            $chks += ord($data_last[$j]);
        }
        $this->writebytes($data_first);
        $chks = pack("a8", sprintf("%6s ", decoct($chks)));
        $this->writebytes($chks.$data_last);
    }
}
