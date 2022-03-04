<?

/**
 * 파일 타입
 */
abstract class FileType {
	const IPA = 0;
	const APK = 1;
	const Unknown = 2;
}

/**
 * OTA 배포 데이터 클래스
 */
class OTADistribution {
	const FILES_DIRECTORY = "files/";
	const DESCRIPTION_FILE = "description.txt";
	const IPA_INFO_PLIST_FILE = "Info.plist";
	const IPA_MANIFEST_FILE = "manifest.plist";
	const IPA_DOWNLOAD_URL_PREFIX = "itms-services://?action=download-manifest&url=";
	const APK_MENIFEST_FILE = "AndroidManifest.xml";

	// 파일 경로
	protected $path;
	// 대상 디렉토리 경로 (파일이 저장되는 곳)
	protected $directory;
	// URL
	protected $baseURL;
	// 파일명
	public $fileName;
	// 파일타입 (IPA, APK)
	public $fileType;
	// 다운로드 URL
	public $downloadURL;
	// 설명
	public $description;
	// 앱 식별자 (iOS: bundleIdentifier, AOS: AppName)
	public $appIdentfier;
	// 앱 버전 
	public $appVersion;
	// 앱 빌드번호
	public $appBuildNumber;

	/**
	 * Constructs a new instance.
	 *
	 * @param      String  $path       The path
	 * @param      String  $directory  The directory
	 */
	public function __construct(String $path, String $directory) {
		$this->baseURL = "http".((!empty($_SERVER['HTTPS'])) ? "s" : "")."://".$_SERVER['SERVER_NAME']."/";
		$this->path = $path;
		$this->directory = $directory;

		$ext = pathinfo($path, PATHINFO_EXTENSION);
		switch (strtoupper($ext)) {
			case "IPA":
				$this->fileType = FileType::IPA;
				$this->fileName = basename($path, ".".$ext);
				break;
			
			case "APK":
				$this->fileType = FileType::APK;
				$this->fileName = basename($path, ".".$ext);
				break;

			default:
				$this->fileType = FileType::Unknown;
				break;
		}

		$this->excute();
	}


	/* Common */	

	/**
	 * EXCUTE
	 */
	function excute() {
		$resDir = OTADistribution::FILES_DIRECTORY.$this->directory."/".$this->fileName."/";
		switch ($this->fileType) {
			case FileType::IPA:
				$this->createDirectoryIfNeeded($resDir);
				$this->createAndReadDescription($resDir);
				$this->generateDownloadURL($resDir, $this->path);
				break;

			case FileType::APK:
				$this->createDirectoryIfNeeded($resDir);
				$this->createAndReadDescription($resDir);
				$this->generateDownloadURL($resDir, $this->path);
				break;
			
			default:
				break;
		}
	}

	
	/**
	 * Creates a directory if needed.
	 *
	 * @param      String  $name   The name
	 */
	function createDirectoryIfNeeded(String $name) {
		if (!is_dir($name)) {
			if (!mkdir($name)) {
				die("폴더 생성에 실패했습니다. 권한이 있는지 확인이 필요합니다.");
			}
		}
	}

	/**
	 * Creates and read description text.
	 *
	 * @param      String  $resDir  The resource dir
	 */
	function createAndReadDescription(String $resDir) {
		if (!file_exists($resDir.OTADistribution::DESCRIPTION_FILE)) {
			if (!file_put_contents($resDir.OTADistribution::DESCRIPTION_FILE, "description")) {
				die("description 파일 생성에 실패했습니다. 권한이 있는지 확인이 필요합니다.");
			}
		}

		$this->description = file_get_contents($resDir.OTADistribution::DESCRIPTION_FILE);
		$this->description = nl2br(rtrim($this->description));
	}


	/**
	 * Generate Download URL
	 *
	 * @param      String  $resDir  The resource dir
	 * @param      String  $path    The path
	 */
	function generateDownloadURL(String $resDir, String $path) {
		switch ($this->fileType) {
			case FileType::IPA:
				$this->getInfoPlistFromIPAIfNeeded($resDir, $path);
				$this->createIPAMenifest($resDir);
				break;

			case FileType::APK:
				$this->getAndroidMenifestXMLFromAPKIfNeeded($resDir, $path);
				$this->parseAndroidMenifest($resDir);
				$this->downloadURL = $this->baseURL.$this->path;
				break;

			default:
				break;
		}
	}


	/* APK Stuff */


	/**
	 * Gets the android menifest xml from apk if needed.
	 *
	 * @param      String  $resDir   The resource dir
	 * @param      String  $apkPath  The apk path
	 */
	function getAndroidMenifestXMLFromAPKIfNeeded(String $resDir, String $apkPath) {
		if (!file_exists($resDir.OTADistribution::APK_MENIFEST_FILE)) {
			$this->unzipFile($apkPath, OTADistribution::APK_MENIFEST_FILE, $resDir);
		}
	}


	/**
	 * Parse AndroidMenifest File
	 *
	 * @param      String  $resDir  The resource dir
	 */
	function parseAndroidMenifest(String $resDir) {
		if (file_exists(dirname(__FILE__)."/classes/apkParser/apkParser.php")) {
			require_once(dirname(__FILE__)."/classes/apkParser/apkParser.php");
			$xml = file_get_contents($resDir.OTADistribution::APK_MENIFEST_FILE);
			try {
				$parser = new ApkParser();
				$parser->parseString($xml);
				$this->appIdentfier = $parser->getAppName();
				$this->appVersion = $parser->getVersionName();
				$this->appBuildNumber = $parser->getVersionCode();
			} catch (Exception $e) {

			}
		}
	}


	/* IPA Stuff */

	/**
	 * Gets the info plist from ipa if needed.
	 *
	 * @param      String  $resDir   The resource dir
	 * @param      String  $ipaPath  The ipa path
	 */
	function getInfoPlistFromIPAIfNeeded(String $resDir, String $ipaPath) {
		if (!file_exists($resDir.OTADistribution::IPA_INFO_PLIST_FILE)) {
			$this->unzipFile($ipaPath, OTADistribution::IPA_INFO_PLIST_FILE, $resDir);
		}
	}


	/**
	 * Creates an menifest for ipa download.
	 *
	 * @param      String  $resDir  The resource dir
	 */
	function createIPAMenifest(String $resDir) {
		if (file_exists(dirname(__FILE__)."/classes/cfpropertylist/CFPropertyList.php")) {
			require_once(dirname(__FILE__)."/classes/cfpropertylist/CFPropertyList.php");

			$plist = new CFPropertyList($resDir.OTADistribution::IPA_INFO_PLIST_FILE);
			$plistArray = $plist->toArray();

			$bundleIdentifier = $plistArray['CFBundleIdentifier'];
			$bundleShortVersionString = $plistArray['CFBundleShortVersionString'];
			$bundleVersion = $plistArray['CFBundleVersion'];
			$bundleDisplayName = $plistArray['CFBundleDisplayName'];
			$title = "$bundleDisplayName [$bundleShortVersionString($bundleVersion)]";

			$this->appIdentfier = $bundleIdentifier;
			$this->appVersion = $bundleShortVersionString;
			$this->appBuildNumber = $bundleVersion;

			$manifest = '
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
<key>items</key>
<array>
	<dict>
		<key>assets</key>
		<array>
			<dict>
				<key>kind</key>
				<string>software-package</string>
				<key>url</key>
				<string>'.$this->baseURL.$this->path.'</string>
			</dict>
		</array>
		<key>metadata</key>
		<dict>
			<key>bundle-identifier</key>
			<string>'.$bundleIdentifier.'</string>
			<key>bundle-version</key>
			<string>'.$bundleShortVersionString.'</string>
			<key>kind</key>
			<string>software</string>
			<key>platform-identifier</key>
			<string>com.apple.platform.iphoneos</string>
			<key>title</key>
			<string>'.$title.'</string>
		</dict>
	</dict>
</array>
</dict>
</plist>';


			if (file_put_contents($resDir.OTADistribution::IPA_MANIFEST_FILE, $manifest)) {
				$this->downloadURL = OTADistribution::IPA_DOWNLOAD_URL_PREFIX.$this->baseURL.$resDir.OTADistribution::IPA_MANIFEST_FILE;
			} else {
				die('manifest.plist 생성중 오류가 발생했습니다. 권한이 있는지 확인이 필요합니다.');
			}
		}
	}


	/* Utilities */

	/**
	 * unzip specific entry file
	 *
	 * @param      String  $zipPath  The zip path
	 * @param      String  $name     File name
	 * @param      String  $to       Extract path
	 */
	protected function unzipFile(String $zipPath, String $name, String $to) {
		$zip = zip_open($zipPath);
		if ($zip) {
			while ($zip_entry = zip_read($zip)) {
				$fileinfo = pathinfo(zip_entry_name($zip_entry));
				if ($fileinfo['basename'] == $name) {
					$fp = fopen($to.$fileinfo['basename'], "w");
					if (zip_entry_open($zip, $zip_entry, "r")) {
						$buffer = zip_entry_read($zip_entry, zip_entry_filesize($zip_entry));
						fwrite($fp, "$buffer");
						zip_entry_close($zip_entry);
						fclose($fp);
					}
				}
			}
			zip_close($zip);
		}
	}
	
}

?>