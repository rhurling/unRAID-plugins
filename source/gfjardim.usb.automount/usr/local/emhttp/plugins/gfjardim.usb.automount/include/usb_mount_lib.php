<?
$paths = array('smb_conf'        => '/etc/samba/smb.conf',
               'smb_extra'       => '/boot/config/smb-extra.conf',
               'smb_usb_shares'  => '/etc/samba/smb-usb-shares',
               'usb_mount_point' => '/mnt/usb',
               'log'             => '/var/log/usb_automount.log');

$echo = function($m) { echo "<pre>".print_r($m,TRUE)."</pre>";};

function debug($m){
  $c = (is_file($GLOBALS['paths']['log'])) ? @file($GLOBALS['paths']['log'],FILE_IGNORE_NEW_LINES) : array();
  $c[] = date("D M j G:i:s T Y").": $m";
  file_put_contents($GLOBALS['paths']['log'], implode(PHP_EOL, $c));
}


function listDir($root) {
  $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
                                        RecursiveIteratorIterator::SELF_FIRST,
                                        RecursiveIteratorIterator::CATCH_GET_CHILD);
  $paths = array();
  foreach ($iter as $path => $fileinfo) {
    if (! $fileinfo->isDir()) $paths[] = $path;
  }
  return $paths;
}

function formatBytes($size) {
  if ($size == 0){ return "0 B";}
  $base = log($size) / log(1024);
  $suffix = array('B', 'kB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB');
  return round(pow(1024, $base - floor($base)), 1) ." ". $suffix[floor($base)];
}


function safe_filename($string) {
  $string = htmlentities($string, ENT_QUOTES, 'UTF-8');
  $string = preg_replace('~&([a-z]{1,2})(acute|cedil|circ|grave|lig|orn|ring|slash|th|tilde|uml);~i', '$1', $string);
  $string = html_entity_decode($string, ENT_QUOTES, 'UTF-8');
  $string = preg_replace(array('~[^0-9a-z]~i', '~[ -]+~'), '_', $string);
  $string = str_replace("_x20", " ", $string);
  return trim($string);
}


function exist_in_file($file, $val) {
  foreach (@file($file) as $line) if (preg_match("%${val}%i", $line)) return true;
  return false;
}


function is_mounted($dev) {
  return (shell_exec("mount 2>&1|grep -c '${dev} '") == 0) ? FALSE : TRUE;
}


function is_shared($name) {
  return ( shell_exec("smbclient -g -L localhost -U% 2>&1|awk -F'|' '/Disk/{print $2}'|grep -c '${name}'") == 0 ) ? FALSE : TRUE;
}


function do_mount($dev, $dir) {
  if (! is_mounted($dev)) {
    @mkdir($dir,0777,TRUE);
    $cmd = "mount -t auto -o auto,async,nodev,nosuid,umask=000 '${dev}' '${dir}'";
    debug("Mounting drive with command: $cmd");
    $o = shell_exec($cmd." 2>&1");
    foreach (range(0,5) as $t) {
      if (is_mounted($dev)) {
        debug("Successfully mounted '${dev}' on '${dir}'"); return TRUE;
      } else { sleep(0.5);}
    }
    debug("Mount of ${dev} failed. Error message: $o"); return FALSE;
  }
}


function do_unmount($dev, $dir) {
  if (shell_exec("mount 2>&1|grep -c '${dev} '") != 0){
    @mkdir($dir,0777,TRUE);
    debug("Unmounting ${dev}...");
    shell_exec("umount '${dev}'");
    for ($i=0; $i < 10; $i++) {
      if (! is_mounted($dev)){
        rmdir($dir);
        debug("Successfully unmounted '$dev'"); return TRUE;
      } else { sleep(0.5);}
    }
    debug("Unmount of ${dev} failed. Error message: $o"); return FALSE;
  }
}


function add_smb_share($dir, $share_name) {
  global $paths;
  $share_conf = preg_replace("#\s+#", "_", realpath($paths['smb_usb_shares'])."/".$share_name.".conf");
  $share_cont = sprintf("[%s]\npath = %s\nread only = No\nguest ok = Yes\ncreate mode = 0644\ndirectory mode = 0755 ", $share_name, $dir);
  @mkdir($paths['smb_usb_shares'],0755,TRUE);
  debug("Defining share '$share_name' on file '$share_conf' .");
  file_put_contents($share_conf, $share_cont);
  if (! exist_in_file($paths['smb_extra'], $share_name)) {
    debug("Adding share $share_name to ".$paths['smb_extra']);
    $c = (is_file($paths['smb_extra'])) ? @file($paths['smb_extra'],FILE_IGNORE_NEW_LINES) : array();
    $c[] = ""; $c[] = "include = $share_conf";
    # Do Cleanup
    $smb_extra_includes = array_unique(preg_grep("/include/i", $c));
    foreach($smb_extra_includes as $key => $inc) if( ! is_file(parse_ini_string($inc)['include'])) unset($smb_extra_includes[$key]); 
    $c = array_merge(preg_grep("/include/i", $c, PREG_GREP_INVERT), $smb_extra_includes);
    $c = preg_replace('/\n\s*\n\s*\n/s', PHP_EOL.PHP_EOL, implode(PHP_EOL, $c));
    file_put_contents($paths['smb_extra'], $c);
  }
  debug("Reloading Samba configuration. ");
  shell_exec("killall -s 1 smbd;killall -s 1 nmbd");
  shell_exec("/usr/bin/smbcontrol $(cat /var/run/smbd.pid) reload-config");
  if(is_shared($share_name)) {
    debug("Directory '${dir}' shared successfully."); return TRUE;
  } else {
    debug("Sharing directory '${dir}' failed."); return FALSE;
  }
}


function rm_smb_share($dir, $share_name) {
  global $paths;
  $share_conf = preg_replace("#\s+#", "_", realpath($paths['smb_usb_shares'])."/".$share_name.".conf");
  debug("Removing share definitions from '$share_conf'.");
  @unlink($share_conf);
  if (exist_in_file($paths['smb_extra'], $share_conf)) {
    debug("Removing share definitions from ".$paths['smb_extra']);
    $c = (is_file($paths['smb_extra'])) ? @file($paths['smb_extra'],FILE_IGNORE_NEW_LINES) : array();
    # Do Cleanup
    $smb_extra_includes = array_unique(preg_grep("/include/i", $c));
    foreach($smb_extra_includes as $key => $inc) if(! is_file(parse_ini_string($inc)['include'])) unset($smb_extra_includes[$key]); 
    $c = array_merge(preg_grep("/include/i", $c, PREG_GREP_INVERT), $smb_extra_includes);
    $c = preg_replace('/\n\s*\n\s*\n/s', PHP_EOL.PHP_EOL, implode(PHP_EOL, $c));
    file_put_contents($paths['smb_extra'], $c);
  }
  debug("Reloading Samba configuration. ");
  shell_exec("/usr/bin/smbcontrol $(cat /var/run/smbd.pid) close-share '${share_name}'");
  shell_exec("/usr/bin/smbcontrol $(cat /var/run/smbd.pid) reload-config");
  if(! is_shared($share_name)) {
    debug("Successfully removed share '${share_name}'."); return TRUE;
  } else {
    debug("Removal of share '${share_name}' failed."); return FALSE;
  }
}


function get_usb_disks() {
  $disks = array();
  foreach(array_diff(scandir("/dev/disk/by-path"), array(".","..")) as $key => $d){
    if (preg_match("/.*(usb).*?-part\d+/i", $d)){
      $device = realpath("/dev/disk/by-path/$d");
      if ($device != realpath("/dev/disk/by-label/UNRAID")) {
        $disks[] = $device;
      }
    }
  }
  return $disks;
}


function get_all_disks_info() {
  $o = array();
  foreach(get_usb_disks() as $d){
    $o[] = get_partition_info($d);
  }
  return $o;
}


function get_partition_info($device){
  global $_ENV, $paths;
  $f_size = function($s) { return (is_numeric(trim($s))) ? formatBytes($s*1024) : "";};
  $disk = array();
  $attrs = (isset($_ENV['DEVTYPE'])) ? $_ENV : parse_ini_string(shell_exec("udevadm info --query=property --path $(udevadm info -q path -n $device )"));
  if ($attrs['DEVTYPE'] == "partition") { 
    $disk['serial'] = safe_filename($attrs['ID_SERIAL']);
    $disk['device'] = $device; 
    $disk['label']  = (isset($attrs['ID_FS_LABEL_ENC'])) ? safe_filename($attrs['ID_FS_LABEL_ENC']) : safe_filename($attrs['ID_SERIAL']);
    preg_match_all("#(.*?)(\d+$)#", $device, $matches);
    $disk['label']  = (count(preg_grep("%".$matches[1][0]."%i", get_usb_disks())) > 1) ? $disk['label']."-part".$matches[2][0] : $disk['label'];
    $disk['fstype'] = safe_filename($attrs['ID_FS_TYPE']);
    $disk['target'] = trim(shell_exec("df --output=target ${device}|grep -v 'Mounted\|/dev'"));
    $disk['size']   = formatBytes($attrs['ID_PART_ENTRY_SIZE']*512);
    $disk['used']   = $f_size(shell_exec("df --output=used,target ${device}|grep -v 'Mounted\|/dev'|awk '{print $1}'"));
    $disk['avail']  = $f_size(shell_exec("df --output=avail,target ${device}|grep -v 'Mounted\|/dev'|awk '{print $1}'"));
    $disk['mountpoint'] = preg_replace("%\s+%", "_", sprintf("%s/%s", $paths['usb_mount_point'], $disk['label']));
  }
  return $disk;
}

### From this on it's MTP related functions, and it still a WIP.

function do_mount_mtp($dev, $dir) {
  if (! is_mounted($dir)) {
    @mkdir($dir,0777,TRUE);
    $cmd = "/usr/sbin/go-mtpfs -allow-other='true' -dev='${dev}' '${dir}' >/dev/null 2>&1 &";
    debug("Mounting drive with command: $cmd");
    $o = shell_exec($cmd);
    foreach (range(0,5) as $t) {
      if (is_mounted($dev)) {
        debug("Successfully mounted '${dev}'' on '${dir}'"); return TRUE;
      } else { sleep(0.5);}
    }
    debug("Mount of ${dev} failed. Error message: $o"); return FALSE;
  }
}


function get_all_android_info() {
  $disks = array();
  foreach(get_mtp_devices() as $d){
    $disks[] = get_android_info($d);
  }
  return $disks;
}


function get_mtp_devices() {
  $mtp = array();
  foreach (listDir(realpath('/dev/bus/usb')) as $block) {
    $info = parse_ini_string(shell_exec("udevadm info --query=property --path $(udevadm info -q path -n ${block} )"));
    if ($info['ID_SERIAL_SHORT']) {
      $r = shell_exec("/usr/sbin/go-mtpfs -dev='${info[ID_SERIAL_SHORT]}' /bogus/test/dir 2>&1 | grep -c mount");
      if ($r > 0) {
        $mtp[] = $block;
      }
    }
  }
  natcasesort($mtp);
  return $mtp;
}


function get_android_info($device){
  echo $device;
  global $_ENV;
  $f_size = function($s) { return (is_numeric(trim($s))) ? formatBytes($s*1024) : "";};
  $disk = array();
  $attrs = (isset($_ENV['DEVTYPE'])) ? $_ENV : parse_ini_string(shell_exec("udevadm info --query=property --path $(udevadm info -q path -n $device )"));
  $disk['serial'] = safe_filename($attrs['ID_SERIAL_SHORT']);
  $disk['device'] = $device; 
  $disk['label']  = safe_filename($attrs['ID_SERIAL']);
  $disk['fstype'] = "mtp";
  $disk['target'] = trim(shell_exec("df --output=target ${device}|grep -v 'Mounted\|/dev'"));
  $disk['size']   = formatBytes($attrs['ID_PART_ENTRY_SIZE']*512);
  $disk['used']   = $f_size(shell_exec("df --output=used,target ${device}|grep -v 'Mounted\|/dev'|awk '{print $1}'"));
  $disk['avail']  = $f_size(shell_exec("df --output=avail,target ${device}|grep -v 'Mounted\|/dev'|awk '{print $1}'"));
  return $disk;
}


?>