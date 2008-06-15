<?php

# ============================================================================
# This is a script to retrieve information from a MySQL server for input to a
# Cacti graphing process.
#
# This program is copyright (c) 2007 Baron Schwartz. Feedback and improvements
# are welcome.
#
# THIS PROGRAM IS PROVIDED "AS IS" AND WITHOUT ANY EXPRESS OR IMPLIED
# WARRANTIES, INCLUDING, WITHOUT LIMITATION, THE IMPLIED WARRANTIES OF
# MERCHANTIBILITY AND FITNESS FOR A PARTICULAR PURPOSE.
#
# This program is free software; you can redistribute it and/or modify it under
# the terms of the GNU General Public License as published by the Free Software
# Foundation, version 2.
#
# You should have received a copy of the GNU General Public License along with
# this program; if not, write to the Free Software Foundation, Inc., 59 Temple
# Place, Suite 330, Boston, MA  02111-1307  USA.
# ============================================================================

# ============================================================================
# Define MySQL connection constants in config.php.  Arguments explicitly passed
# in from Cacti will override these.  However, if you leave them blank in Cacti
# and set them here, you can make life easier.
# ============================================================================
$mysql_user = 'cactiuser';
$mysql_pass = 'cactiuser';

$heartbeat  = '';      # db.tbl in case you use mk-heartbeat from Maatkit.
$cache_dir  = '/tmp';  # If set, this uses caching to avoid multiple calls.
$poll_time  = 300;     # Adjust to match your polling interval.
# ============================================================================
# You should not need to change anything below this line.
# ============================================================================

# ============================================================================
# TODO items, if anyone wants to improve this script:
# * Permit only some graphs to be fetched/output.
# * Aggregate the processlist and report.
# * Make sure that this can be called by the script server.
# * Calculate query cache fragmentation as a percentage, something like
#   $status['Qcache_frag_bytes']
#     = $status['Qcache_free_blocks'] / $status['Qcache_total_blocks']
#        * $status['query_cache_size'];
# * Add data to support percentage-based metrics, such as
#     * MyISAM key cache hit rate
#     * InnoDB buffer pool hit rate
#     * InnoDB adaptive hash index hit rate
#     * Binlog cache hit rate
# * Calculate relay log position lag
# * Parse the InnoDB line 'mysql tables in use 4, locked 4'
# * Parse the InnoDB line
#   '16 lock struct(s), heap size 3024, undo log entries 1018
# ============================================================================

# ============================================================================
# Define whether you want debugging behavior.
# ============================================================================
$debug = TRUE;
error_reporting($debug ? E_ALL : E_ERROR);

# Make this a happy little script even when there are errors.
$no_http_headers = true;
ini_set('implicit_flush', false); # No output, ever.
ob_start(); # Catch all output such as notices of undefined array indexes.
function error_handler($errno, $errstr, $errfile, $errline) {
   print("$errstr at $errfile line $errline\n");
}
# ============================================================================
# Set up the stuff we need to be called by the script server.
# ============================================================================
if ( file_exists( dirname(__FILE__) . "/../include/global.php") ) {
   # See issue 5 for the reasoning behind this.
   include_once(dirname(__FILE__) . "/../include/global.php");
}
else {
   # Some versions don't have global.php.
   include_once(dirname(__FILE__) . "/../include/config.php");
}

# ============================================================================
# Make sure we can also be called as a script.
# ============================================================================
if (!isset($called_by_script_server)) {
   array_shift($_SERVER["argv"]);
   $result = call_user_func_array("ss_get_mysql_stats", $_SERVER["argv"]);
   if ( !$debug ) {
      # Throw away the buffer, which ought to contain only errors.
      ob_end_clean();
   }
   else {
      ob_end_flush(); # In debugging mode, print out the errors.
   }
   print($result);
}

# ============================================================================
# This is the main function.  Only the $host parameter must be specified.
# Others are filled in from defaults at the top of this file.  If you want to
# specify a port, you must include it in the hostname, like "localhost:3306".
# ============================================================================
function ss_get_mysql_stats( $host, $user = null, $pass = null, $hb_table = null ) {

   # Process connection options and connect to MySQL.
   global $debug, $mysql_user, $mysql_pass, $heartbeat, $cache_dir, $poll_time;

   $user = isset($user) ? $user : $mysql_user;
   $pass = isset($pass) ? $pass : $mysql_pass;
   $hb_table = isset($hb_table) ? $hb_table : $heartbeat;
   $conn = @mysql_connect($host, $user, $pass);
   if ( !$conn ) {
      die("Can't connect to MySQL: " . mysql_error());
   }
   $cache_file = "$cache_dir/$host-mysql_cacti_stats.txt";

   # First, check the cache.
   $fp = null;
   if ( $cache_dir ) {
      # This will block if someone else is accessing the file.
      $result = run_query(
         "SELECT GET_LOCK('cacti_monitoring', $poll_time) AS ok", $conn);
      $row = @mysql_fetch_assoc($result);
      if ( $row['ok'] ) { # Nobody else had the file locked.
         if ( file_exists($cache_file) && filesize($cache_file) > 0
            && filectime($cache_file) + ($poll_time/2) > time() )
         {
            # The file is fresh enough to use.
            $arr = file($cache_file);
            # The file ought to have some contents in it!  But just in case it
            # doesn't... (see issue #6).
            if ( count($arr) ) {
               run_query("SELECT RELEASE_LOCK('cacti_monitoring')", $conn);
               return $arr[0];
            }
            else {
               if ( $debug ) {
                  trigger_error("The function file($cache_file) returned nothing!\n");
               }
            }
         }
      }
      if ( !$fp = fopen($cache_file, 'w+') ) {
         die("Cannot open file '$cache_file'");
      }
   }

   # Set up variables.
   $status = array( # Holds the result of SHOW STATUS, SHOW INNODB STATUS, etc
      # Define some indexes so they don't cause errors with += operations.
      'transactions'          => 0,
      'relay_log_space'       => 0,
      'binary_log_space'      => 0,
      'current_transactions'  => 0,
      'locked_transactions'   => 0,
      'active_transactions'   => 0,
   );

   # Get SHOW STATUS and convert the name-value array into a simple
   # associative array.
   $result = run_query("SHOW /*!50002 GLOBAL */ STATUS", $conn);
   while ($row = @mysql_fetch_row($result)) {
      $status[$row[0]] = $row[1];
   }

   # Get SHOW VARIABLES and convert the name-value array into a simple
   # associative array.
   $result = run_query("SHOW VARIABLES", $conn);
   while ($row = @mysql_fetch_row($result)) {
      $status[$row[0]] = $row[1];
   }

   # Get SHOW SLAVE STATUS.
   $result = run_query("SHOW SLAVE STATUS", $conn);
   while ($row = @mysql_fetch_assoc($result)) {
      # Must lowercase keys because different versions have different
      # lettercase.
      $row = array_change_key_case($row, CASE_LOWER);
      $status['relay_log_space']  = $row['relay_log_space'];
      $status['slave_lag']        = $row['seconds_behind_master'];

      # Check replication heartbeat, if present.
      if ( $hb_table ) {
         $result = run_query(
            "SELECT GREATEST(0, UNIX_TIMESTAMP() - UNIX_TIMESTAMP(ts) - 1)"
            . "FROM $hb_table WHERE id = 1", $conn);
         $row2 = @mysql_fetch_row($result);
         $status['slave_lag'] = $row2[0];
      }

      # Scale slave_running and slave_stopped relative to the slave lag.
      $status['slave_running'] = ($row['slave_sql_running'] == 'Yes')
         ? $status['slave_lag'] : 0;
      $status['slave_stopped'] = ($row['slave_sql_running'] == 'Yes')
         ? 0 : $status['slave_lag'];
   }

   # Get info on master logs.
   $binlogs = array(0);
   if ( $status['log_bin'] == 'ON' ) { # See issue #8
      $result = run_query("SHOW MASTER LOGS", $conn);
      while ($row = @mysql_fetch_assoc($result)) {
         $row = array_change_key_case($row, CASE_LOWER);
         # Older versions of MySQL may not have the File_size column in the
         # results of the command.
         if ( array_key_exists('file_size', $row) ) {
            $binlogs[] = $row['file_size'];
         }
         else {
            break;
         }
      }
   }

   # Get SHOW INNODB STATUS and extract the desired metrics from it.
   $innodb_txn = false;
   if ( $status['have_innodb'] == 'YES' ) { # See issue #8.
      $result        = run_query("SHOW /*!50000 ENGINE*/ INNODB STATUS", $conn);
      $innodb_array  = @mysql_fetch_assoc($result);
      $flushed_to    = false;
      $innodb_lsn    = false;
      $innodb_prg    = false;
      $spin_waits    = array();
      $spin_rounds   = array();
      $os_waits      = array();
      foreach ( explode("\n", $innodb_array['Status']) as $line ) {
         $row = explode(' ', $line);

         # SEMAPHORES
         if (strstr($line, 'Mutex spin waits')) {
            $spin_waits[]  = tonum($row[3]);
            $spin_rounds[] = tonum($row[5]);
            $os_waits[]    = tonum($row[8]);
         }
         elseif (strstr($line, 'RW-shared spins')) {
            $spin_waits[] = tonum($row[2]);
            $spin_waits[] = tonum($row[8]);
            $os_waits[]   = tonum($row[5]);
            $os_waits[]   = tonum($row[11]);
         }

         # TRANSACTIONS
         elseif ( strstr($line, 'Trx id counter')) {
            # The beginning of the TRANSACTIONS section: start counting
            # transactions
            $innodb_txn = array($row[3], $row[4]);
         }
         elseif (strstr($line, 'Purge done for trx')) {
            # PHP can't do big math, so I send it to MySQL.
            $innodb_prg = array($row[6], $row[7]);
         }
         elseif (strstr($line, 'History list length')) {
            $status['history_list'] = tonum($row[3]);
         }
         elseif ( $innodb_txn && strstr($line, '---TRANSACTION')) {
            $status['current_transactions'] += 1;
            if ( strstr($line, 'ACTIVE') ) {
               $status['active_transactions'] += 1;
            }
         }
         elseif ( $innodb_txn && strstr($line, 'LOCK WAIT') ) {
            $status['locked_transactions'] += 1;
         }
         elseif ( strstr($line, 'read views open inside')) {
            $status['read_views'] = tonum($row[0]);
         }

         # FILE I/O
         elseif (strstr($line, 'OS file reads')) {
            $status['file_reads']  = tonum($row[0]);
            $status['file_writes'] = tonum($row[4]);
            $status['file_fsyncs'] = tonum($row[8]);
         }
         elseif (strstr($line, 'Pending normal aio')) {
            $status['pending_normal_aio_reads']  = tonum($row[4]);
            $status['pending_normal_aio_writes'] = tonum($row[7]);
         }
         elseif (strstr($line, 'ibuf aio reads')) {
            $status['pending_ibuf_aio_reads'] = tonum($row[4]);
            $status['pending_aio_log_ios']    = tonum($row[7]);
            $status['pending_aio_sync_ios']   = tonum($row[10]);
         }
         elseif (strstr($line, 'Pending flushes (fsync)')) {
            $status['pending_log_flushes']      = tonum($row[4]);
            $status['pending_buf_pool_flushes'] = tonum($row[7]);
         }

         # INSERT BUFFER AND ADAPTIVE HASH INDEX
         elseif (strstr($line, 'merged recs')) {
            $status['ibuf_inserts'] = tonum($row[0]);
            $status['ibuf_merged']  = tonum($row[2]);
            $status['ibuf_merges']  = tonum($row[5]);
         }

         # LOG
         elseif (strstr($line, "log i/o's done")) {
            $status['log_writes'] = tonum($row[0]);
         }
         elseif (strstr($line, "pending log writes")) {
            $status['pending_log_writes']  = tonum($row[0]);
            $status['pending_chkp_writes'] = tonum($row[4]);
         }
         elseif (strstr($line, "Log sequence number")) {
            $innodb_lsn = array($row[3], $row[4]);
         }
         elseif (strstr($line, "Log flushed up to")) {
            # Since PHP can't handle 64-bit numbers, we'll ask MySQL to do it for
            # us instead.  And we get it to cast them to strings, too.
            $flushed_to = array($row[6], $row[7]);
         }

         # BUFFER POOL AND MEMORY
         elseif (strstr($line, "Buffer pool size")) {
             $status['pool_size'] = tonum($row[5]);
         }
         elseif (strstr($line, "Free buffers")) {
             $status['free_pages'] = tonum($row[8]);
         }
         elseif (strstr($line, "Database pages")) {
             $status['database_pages'] = tonum($row[6]);
         }
         elseif (strstr($line, "Modified db pages")) {
             $status['modified_pages'] = tonum($row[4]);
         }
         elseif (strstr($line, "Pages read") ) {
             $status['pages_read']    = tonum($row[2]);
             $status['pages_created'] = tonum($row[4]);
             $status['pages_written'] = tonum($row[6]);
         }

         # ROW OPERATIONS
         elseif (strstr($line, 'Number of rows inserted')) {
            $status['rows_inserted'] = tonum($row[4]);
            $status['rows_updated']  = tonum($row[6]);
            $status['rows_deleted']  = tonum($row[8]);
            $status['rows_read']     = tonum($row[10]);
         }
         elseif (strstr($line, "queries inside InnoDB")) {
             $status['queries_inside'] = tonum($row[0]);
             $status['queries_queued']  = tonum($row[4]);
         }
      }
   }

   # Derive some values from other values.

   # PHP sucks at bigint math, so we use MySQL to calculate things that are
   # too big for it.
   if ( $innodb_txn ) {
      $txn = make_bigint_sql($innodb_txn[0], $innodb_txn[1]);
      $lsn = make_bigint_sql($innodb_lsn[0], $innodb_lsn[1]);
      $flu = make_bigint_sql($flushed_to[0], $flushed_to[1]);
      $prg = make_bigint_sql($innodb_prg[0], $innodb_prg[1]);
      $sql = "SELECT CONCAT('', $txn) AS innodb_transactions, "
           . "CONCAT('', ($txn - $prg)) AS unpurged_txns, "
           . "CONCAT('', $lsn) AS log_bytes_written, "
           . "CONCAT('', $flu) AS log_bytes_flushed, "
           . "CONCAT('', ($lsn - $flu)) AS unflushed_log, "
           . "CONCAT('', " . implode('+', $spin_waits) . ") AS spin_waits, "
           . "CONCAT('', " . implode('+', $spin_rounds) . ") AS spin_rounds, "
           . "CONCAT('', " . implode('+', $os_waits) . ") AS os_waits";
      # echo("$sql\n");
      $result = run_query($sql, $conn);
      while ( $row = @mysql_fetch_assoc($result) ) {
         foreach ( $row as $key => $val ) {
            $status[$key] = $val;
         }
      }
      # TODO: I'm not sure what the deal is here; need to debug this.  But the
      # unflushed log bytes spikes a lot sometimes and it's impossible for it to
      # be more than the log buffer.
      $status['unflushed_log']
         = max($status['unflushed_log'], $status['innodb_log_buffer_size']);
   }
   if (count($binlogs)) {
      $sql = "SELECT "
           . "CONCAT('', " . implode('+', $binlogs) . ") AS binary_log_space ";
      # echo("$sql\n");
      $result = run_query($sql, $conn);
      while ( $row = @mysql_fetch_assoc($result) ) {
         foreach ( $row as $key => $val ) {
            $status[$key] = $val;
         }
      }
   }

   # Define the variables to output.  I use shortened variable names so maybe
   # it'll all fit in 1024 bytes for Cactid and Spine's benefit.  This list must
   # stay in sync with the Perl script that generates the templates.
   $keys = array(
       'Key_read_requests'          => 'a0',
       'Key_reads'                  => 'a1',
       'Key_write_requests'         => 'a2',
       'Key_writes'                 => 'a3',
       'history_list'               => 'a4',
       'innodb_transactions'        => 'a5',
       'read_views'                 => 'a6',
       'current_transactions'       => 'a7',
       'locked_transactions'        => 'a8',
       'active_transactions'        => 'a9',
       'pool_size'                  => 'aa',
       'free_pages'                 => 'ab',
       'database_pages'             => 'ac',
       'modified_pages'             => 'ad',
       'pages_read'                 => 'ae',
       'pages_created'              => 'af',
       'pages_written'              => 'ag',
       'file_fsyncs'                => 'ah',
       'file_reads'                 => 'ai',
       'file_writes'                => 'aj',
       'log_writes'                 => 'ak',
       'pending_aio_log_ios'        => 'al',
       'pending_aio_sync_ios'       => 'am',
       'pending_buf_pool_flushes'   => 'an',
       'pending_chkp_writes'        => 'ao',
       'pending_ibuf_aio_reads'     => 'ap',
       'pending_log_flushes'        => 'aq',
       'pending_log_writes'         => 'ar',
       'pending_normal_aio_reads'   => 'as',
       'pending_normal_aio_writes'  => 'at',
       'ibuf_inserts'               => 'au',
       'ibuf_merged'                => 'av',
       'ibuf_merges'                => 'aw',
       'spin_waits'                 => 'ax',
       'spin_rounds'                => 'ay',
       'os_waits'                   => 'az',
       'rows_inserted'              => 'b0',
       'rows_updated'               => 'b1',
       'rows_deleted'               => 'b2',
       'rows_read'                  => 'b3',
       'Table_locks_waited'         => 'b4',
       'Table_locks_immediate'      => 'b5',
       'Slow_queries'               => 'b6',
       'Open_files'                 => 'b7',
       'Open_tables'                => 'b8',
       'Opened_tables'              => 'b9',
       'innodb_open_files'          => 'ba',
       'open_files_limit'           => 'bb',
       'table_cache'                => 'bc',
       'Aborted_clients'            => 'bd',
       'Aborted_connects'           => 'be',
       'Max_used_connections'       => 'bf',
       'Slow_launch_threads'        => 'bg',
       'Threads_cached'             => 'bh',
       'Threads_connected'          => 'bi',
       'Threads_created'            => 'bj',
       'Threads_running'            => 'bk',
       'max_connections'            => 'bl',
       'thread_cache_size'          => 'bm',
       'Connections'                => 'bn',
       'slave_running'              => 'bo',
       'slave_stopped'              => 'bp',
       'Slave_retried_transactions' => 'bq',
       'slave_lag'                  => 'br',
       'Slave_open_temp_tables'     => 'bs',
       'Qcache_free_blocks'         => 'bt',
       'Qcache_free_memory'         => 'bu',
       'Qcache_hits'                => 'bv',
       'Qcache_inserts'             => 'bw',
       'Qcache_lowmem_prunes'       => 'bx',
       'Qcache_not_cached'          => 'by',
       'Qcache_queries_in_cache'    => 'bz',
       'Qcache_total_blocks'        => 'c0',
       'query_cache_size'           => 'c1',
       'Questions'                  => 'c2',
       'Com_update'                 => 'c3',
       'Com_insert'                 => 'c4',
       'Com_select'                 => 'c5',
       'Com_delete'                 => 'c6',
       'Com_replace'                => 'c7',
       'Com_load'                   => 'c8',
       'Com_update_multi'           => 'c9',
       'Com_insert_select'          => 'ca',
       'Com_delete_multi'           => 'cb',
       'Com_replace_select'         => 'cc',
       'Select_full_join'           => 'cd',
       'Select_full_range_join'     => 'ce',
       'Select_range'               => 'cf',
       'Select_range_check'         => 'cg',
       'Select_scan'                => 'ch',
       'Sort_merge_passes'          => 'ci',
       'Sort_range'                 => 'cj',
       'Sort_rows'                  => 'ck',
       'Sort_scan'                  => 'cl',
       'Created_tmp_tables'         => 'cm',
       'Created_tmp_disk_tables'    => 'cn',
       'Created_tmp_files'          => 'co',
       'Bytes_sent'                 => 'cp',
       'Bytes_received'             => 'cq',
       'innodb_log_buffer_size'     => 'cr',
       'unflushed_log'              => 'cs',
       'log_bytes_flushed'          => 'ct',
       'log_bytes_written'          => 'cu',
       'relay_log_space'            => 'cv',
       'binlog_cache_size'          => 'cw',
       'Binlog_cache_disk_use'      => 'cx',
       'Binlog_cache_use'           => 'cy',
       'binary_log_space'           => 'cz',
   );

   # Return the output.
   $output = array();
   foreach ($keys as $key => $short ) {
      $val      = isset($status[$key]) ? $status[$key] : 0;
      $output[] = "$short:$val";
   }
   $result = implode(' ', $output);
   if ( $fp ) {
      if ( fwrite($fp, $result) === FALSE ) {
         die("Cannot write to '$cache_file'");
      }
      fclose($fp);
      run_query("SELECT RELEASE_LOCK('cacti_monitoring')", $conn);
   }
   return $result;
}

# ============================================================================
# Returns SQL to create a bigint from two ulint
# ============================================================================
function make_bigint_sql ($hi, $lo) {
   return "(($hi << 32) + $lo)";
}

# ============================================================================
# Extracts the numbers from a string.  You can't reliably do this by casting to
# an int, because numbers that are bigger than PHP's int (varies by platform)
# will be truncated.  So this just handles them as a string instead.  Note that
# all bigint math is done by sending values in a query to MySQL!  :-)
# ============================================================================
function tonum ( $str ) {
   global $debug;
   preg_match('{(\d+)}', $str, $m); 
   if ( isset($m[1]) ) {
      return $m[1];
   }
   elseif ( $debug ) {
      print_r(debug_backtrace());
   }
   else {
      return 0;
   }
}

# ============================================================================
# Wrap mysql_query in error-handling
# ============================================================================
function run_query($sql, $conn) {
   global $debug;
   $result = @mysql_query($sql, $conn);
   if ( $debug ) {
      $error = @mysql_error($conn);
      if ( $error ) {
         die("Error executing '$sql': $error");
      }
   }
   return $result;
}

?>