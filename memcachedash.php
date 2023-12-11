<?php

// Add your Memcache server(s) configurations
$serversConfig = [
    0 => [
        'host' => '127.0.0.1',
        'port' => 11211,
        'friendlyName' => 'Server 1',
    ],
    1 => [
        'host' => '127.0.0.1',
        'port' => 21211,
        'friendlyName' => 'Server 2',
    ],
];

/**
 * Server model
 */
class Server {
    /** @var string */
    public $host = '127.0.0.1';
    /** @var int */
    public $port = 11211;
    /** @var string */
    public $friendlyName = 'Memcache server';
}

/**
 * Current active server to display data for.
 */
class ActiveServer extends Server {
    /** @var int */
    public $index = 0;
}

/**
 * The requested Memcache item by the user
 */
class UserRequest {
    /** @var string */
    public $key = '';
    /** @var string */
    public $value  = '';
}

/**
 * The item returned from Memcache.
 */
class MemcacheItem {
    /** @var string */
    public $key = '';
    /** @var string */
    public $value = '';
    /** @var string */
    public $type = '';
    /** @var string */
    public $expire = '';
    /** @var int */
    public $bytes = 0;
}

/**
 * Simple memcache dashboard
 */
class MemcacheDashboard
{
    /** @var Server[] | null */
    public $servers = null;

    /** @var ActiveServer | null */
    public $activeServer = null;

    /** @var MemcacheItem[] */
    public $items = [];

    /** @var string[] | false */
    public $stats = false;

    /** @var UserRequest | null */
    public $userRequest = null;

    /** @var Memcache | null */
    private $memcache = null;


    public function __construct($serversConfig)
    {
        $this->initServers($serversConfig);
        $this->setActiveServer();
        $this->initMemcache();
        $this->handleRequest();

        // Only need to fetch the data if is a GET request
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $this->initData();
        }
    }

    /**
     * Create the servers from the config
     * @param $serversConfig
     * @return void
     */
    public function initServers($serversConfig)
    {
        $servers = [];
        foreach ($serversConfig as $k => $v) {
            $server = new Server();
            $server->host = $serversConfig[$k]['host'];
            $server->port = $serversConfig[$k]['port'];
            $server->friendlyName = $serversConfig[$k]['friendlyName'];
            $servers[] = $server;
        }
        $this->servers = $servers;
    }
    
    public function setActiveServer()
    {
        $this->activeServer = new ActiveServer();
        
        if (isset($_GET['server'])) {
            $activeServer = $this->servers[$_GET['server']];
            
            $this->activeServer->index = (int)$_GET['server'];
            $this->activeServer->host = $activeServer->host;
            $this->activeServer->port = $activeServer->port;
            $this->activeServer->friendlyName = $activeServer->friendlyName;
        }
        else {
            $this->activeServer->index = 0;
            $this->activeServer->host = $this->servers[0]->host;
            $this->activeServer->port = $this->servers[0]->port;
            $this->activeServer->friendlyName = $this->servers[0]->friendlyName;
        }
    }

    public function initMemcache()
    {
        $this->memcache = new Memcache();
        $isSuccess = $this->memcache->addServer("{$this->activeServer->host}:{$this->activeServer->port}");
    }

    public function initData()
    {
        $list = array();
        $allSlabs = $this->memcache->getExtendedStats('slabs');

        foreach ($allSlabs as $server => $slabs) {
            foreach ($slabs as $slabId => $slabMeta) {
                if (!is_int($slabId)) {continue;}
                $cdump = $this->memcache->getExtendedStats('cachedump', (int)$slabId, 1000);
                foreach ($cdump as $server => $entries) {
                    if (!is_array($entries)) {continue;}
                    foreach ($entries as $eName => $eData) {
                        $value = $this->memcache->get($eName);
                        $type = gettype($value);
                        $bytes = $eData[0];

                        $formattedDate = 'no expire';
                        if ($eData[1] > 0) {
                            $utcTimezone = new DateTimeZone( 'UTC' );
                            $dateTime = new DateTime('now', $utcTimezone);
                            $dateTime->setTimestamp($eData[1]);
                            $dateTime->setTimezone(new DateTimeZone('America/Los_Angeles'));
                            $formattedDate = $dateTime->format('Y-m-d H:i:s');  // Pacific time
                        }

                        if (is_object($value) || is_array($value)) {
                            $value = is_object($value) ? json_decode(json_encode($value), true) : $value;
                            $value = '<pre class="alert alert-warning">' . print_r($value, true) . '</pre>';
                        }
                        $item = new MemcacheItem();
                        $item->key = $eName;
                        $item->value = $value;
                        $item->type = $type;
                        $item->expire = $formattedDate;
                        $item->bytes = $bytes;
                        
                        $list[$eName] = $item;
                    }
                }
            }
        }
        
        $this->items = $list;
        $this->stats = $this->memcache->getStats();
    }

    public function handleRequest()
    {
        // get
        if (isset($_GET['getkey'])) {
            $key = trim($_GET['getkey']);
            $result = $this->memcache->get($key);
            
            $getRequest = new UserRequest();
            $getRequest->key = $key;
            $getRequest->value = $result ?: '';
            
            $this->userRequest = $getRequest;
        }

        // set
        if (isset($_POST['key']) && isset($_POST['value'])) {
            $key = filter_input(INPUT_POST, 'key',  FILTER_SANITIZE_SPECIAL_CHARS);
            $expire = filter_input(INPUT_POST, 'expire',  FILTER_SANITIZE_NUMBER_INT);


            // strip tags from value for at least some security
            $value = preg_replace('/[<>]/', '', $_POST['value']);

            $isSuccess = $this->memcache->set($key, $value, 0, $expire);
            header("Location: " . $_SERVER['PHP_SELF']);
        }

        // delete
        if (isset($_POST['del'])) {
            $delKey = filter_input(INPUT_POST, 'del', FILTER_SANITIZE_SPECIAL_CHARS);
            $isSuccess = $this->memcache->delete($delKey);
            header("Location: " . $_SERVER['PHP_SELF']);
        }

        // flush
        if (isset($_POST['flush'])) {
            $isSuccess = $this->memcache->flush();
            header("Location: " . $_SERVER['PHP_SELF']);
        }
    }
}

// data
$dash = new MemcacheDashboard($serversConfig);

// major areas
$servers = $dash->servers;
$activeServer = $dash->activeServer;
$items = $dash->items;
$stats = $dash->stats;
$getRequest = $dash->userRequest;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Memcache Dash</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <link href="https://cdn.datatables.net/v/dt/dt-1.13.8/datatables.min.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg bg-primary" role="navigation" data-bs-theme="dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="#">Memcache Dashboard</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarText"
                    aria-controls="navbarText" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarText">
                
                <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                    <!-- No nav items, but add some if needed -->    
                </ul>
                
                <div class="navbar-text d-flex align-items-center">
                    <div id="js-change-server-loader" class="me-3" hidden>
                        <div class="spinner-border" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
      
                    <select id="js-change-server" class="form-select me-4" aria-label="Select memcache server">
                        <? foreach ($servers as $k => $v): ?>
                            <option value="<?= $k ?>" <?= $k === $activeServer->index ? 'selected' : '' ?>><?= $v->friendlyName ?></option>
                        <? endforeach; ?>
                    </select>
                    <div class="me-3">
                        <form id="js-flush-form" action="<?= $_SERVER['PHP_SELF'] ?>" method="post">
                            <input type="hidden" name="flush" value="flush">
                            <button type="submit" class="btn btn-danger">FLUSH</button>
                        </form>
                    </div>
                    <div class="text-nowrap">
                        Host: <?= htmlspecialchars($activeServer->host, ENT_NOQUOTES)  ?> 
                        | Port: <?= htmlspecialchars($activeServer->port, ENT_NOQUOTES)  ?>
                    </div>
                </div>
            </div>
        </div>
    </nav>
    
    <main class="container-fluid mt-4">
        
        <a id="stats">&nbsp;</a>
        <div class="accordion" id="infoAccordion">
            <div class="accordion-item">
                <h2 class="accordion-header">
                    <button class="accordion-button" type="button" data-bs-toggle="collapse"
                            data-bs-target="#infoCollapse" aria-expanded="false" aria-controls="infoCollapse">
                        <b class="me-2">Memcache Server Info</b> <span class="text-muted">(expand to see)</span>
                    </button>
                </h2>
                <div id="infoCollapse" class="accordion-collapse collapse" data-bs-parent="#infoAccordion">
                    <div class="accordion-body">
                        <div class="row">
                            <div class="col">
                                <table class="table table-sm caption-top table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th>Stat</th>
                                            <th>Value</th>
                                        </tr>
                                    </thead>
                                    <tbody class="">
                                        <? foreach ($stats as $key => $value): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($key, ENT_NOQUOTES)  ?></td>
                                                <td><?= htmlspecialchars($value, ENT_NOQUOTES)  ?></td>
                                            </tr>
                                        <? endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="col">
                                <div class="d-flex align-items-baseline">
                                    <h4 class="m-0 me-3">Stats Key</h4>
                                    <div>
                                        See
                                        <a href="https://github.com/memcached/memcached/blob/master/doc/protocol.txt"
                                           target="_blank">Stats Docs</a> for more info.
                                    </div>
                                </div>
                                <pre>
|-----------------------+---------+-------------------------------------------|
| Name                  | Type    | Meaning                                   |
|-----------------------+---------+-------------------------------------------|
| pid                   | 32u     | Process id of this server process         |
| uptime                | 32u     | Number of secs since the server started   |
| time                  | 32u     | current UNIX time according to the server |
| version               | string  | Version string of this server             |
| pointer_size          | 32      | Default size of pointers on the host OS   |
|                       |         | (generally 32 or 64)                      |
| rusage_user           | 32u.32u | Accumulated user time for this process    |
|                       |         | (seconds:microseconds)                    |
| rusage_system         | 32u.32u | Accumulated system time for this process  |
|                       |         | (seconds:microseconds)                    |
| curr_items            | 64u     | Current number of items stored            |
| total_items           | 64u     | Total number of items stored since        |
|                       |         | the server started                        |
| bytes                 | 64u     | Current number of bytes used              |
|                       |         | to store items                            |
| max_connections       | 32u     | Max number of simultaneous connections    |
| curr_connections      | 32u     | Number of open connections                |
| total_connections     | 32u     | Total number of connections opened since  |
|                       |         | the server started running                |
| rejected_connections  | 64u     | Conns rejected in maxconns_fast mode      |
| connection_structures | 32u     | Number of connection structures allocated |
|                       |         | by the server                             |
| reserved_fds          | 32u     | Number of misc fds used internally        |
| cmd_get               | 64u     | Cumulative number of retrieval reqs       |
| cmd_set               | 64u     | Cumulative number of storage reqs         |
| cmd_flush             | 64u     | Cumulative number of flush reqs           |
| cmd_touch             | 64u     | Cumulative number of touch reqs           |
| get_hits              | 64u     | Number of keys that have been requested   |
|                       |         | and found present                         |
| get_misses            | 64u     | Number of items that have been requested  |
|                       |         | and not found                             |
| get_expired           | 64u     | Number of items that have been requested  |
|                       |         | but had already expired.                  |
| get_flushed           | 64u     | Number of items that have been requested  |
|                       |         | but have been flushed via flush_all       |
| delete_misses         | 64u     | Number of deletions reqs for missing keys |
| delete_hits           | 64u     | Number of deletion reqs resulting in      |
|                       |         | an item being removed.                    |
| incr_misses           | 64u     | Number of incr reqs against missing keys. |
| incr_hits             | 64u     | Number of successful incr reqs.           |
| decr_misses           | 64u     | Number of decr reqs against missing keys. |
| decr_hits             | 64u     | Number of successful decr reqs.           |
| cas_misses            | 64u     | Number of CAS reqs against missing keys.  |
| cas_hits              | 64u     | Number of successful CAS reqs.            |
| cas_badval            | 64u     | Number of CAS reqs for which a key was    |
|                       |         | found, but the CAS value did not match.   |
| touch_hits            | 64u     | Number of keys that have been touched     |
|                       |         | with a new expiration time                |
| touch_misses          | 64u     | Number of items that have been touched    |
|                       |         | and not found                             |
| auth_cmds             | 64u     | Number of authentication commands         |
|                       |         | handled, success or failure.              |
| auth_errors           | 64u     | Number of failed authentications.         |
| idle_kicks            | 64u     | Number of connections closed due to       |
|                       |         | reaching their idle timeout.              |
| evictions             | 64u     | Number of valid items removed from cache  |
|                       |         | to free memory for new items              |
| reclaimed             | 64u     | Number of times an entry was stored using |
|                       |         | memory from an expired entry              |
| bytes_read            | 64u     | Total number of bytes read by this server |
|                       |         | from network                              |
| bytes_written         | 64u     | Total number of bytes sent by this server |
|                       |         | to network                                |
| limit_maxbytes        | size_t  | Number of bytes this server is allowed to |
|                       |         | use for storage.                          |
| accepting_conns       | bool    | Whether or not server is accepting conns  |
| listen_disabled_num   | 64u     | Number of times server has stopped        |
|                       |         | accepting new connections (maxconns).     |
| time_in_listen_disabled_us                                                  |
|                       | 64u     | Number of microseconds in maxconns.       |
| threads               | 32u     | Number of worker threads requested.       |
|                       |         | (see doc/threads.txt)                     |
| conn_yields           | 64u     | Number of times any connection yielded to |
|                       |         | another due to hitting the -R limit.      |
| hash_power_level      | 32u     | Current size multiplier for hash table    |
| hash_bytes            | 64u     | Bytes currently used by hash tables       |
| hash_is_expanding     | bool    | Indicates if the hash table is being      |
|                       |         | grown to a new size                       |
| expired_unfetched     | 64u     | Items pulled from LRU that were never     |
|                       |         | touched by get/incr/append/etc before     |
|                       |         | expiring                                  |
| evicted_unfetched     | 64u     | Items evicted from LRU that were never    |
|                       |         | touched by get/incr/append/etc.           |
| evicted_active        | 64u     | Items evicted from LRU that had been hit  |
|                       |         | recently but did not jump to top of LRU   |
| slab_reassign_running | bool    | If a slab page is being moved             |
| slabs_moved           | 64u     | Total slab pages moved                    |
| crawler_reclaimed     | 64u     | Total items freed by LRU Crawler          |
| crawler_items_checked | 64u     | Total items examined by LRU Crawler       |
| lrutail_reflocked     | 64u     | Times LRU tail was found with active ref. |
|                       |         | Items can be evicted to avoid OOM errors. |
| moves_to_cold         | 64u     | Items moved from HOT/WARM to COLD LRU's   |
| moves_to_warm         | 64u     | Items moved from COLD to WARM LRU         |
| moves_within_lru      | 64u     | Items reshuffled within HOT or WARM LRU's |
| direct_reclaims       | 64u     | Times worker threads had to directly      |
|                       |         | reclaim or evict items.                   |
| lru_crawler_starts    | 64u     | Times an LRU crawler was started          |
| lru_maintainer_juggles                                                      |
|                       | 64u     | Number of times the LRU bg thread woke up |
| slab_global_page_pool | 32u     | Slab pages returned to global pool for    |
|                       |         | reassignment to other slab classes.       |
| slab_reassign_rescues | 64u     | Items rescued from eviction in page move  |
| slab_reassign_evictions_nomem                                               |
|                       | 64u     | Valid items evicted during a page move    |
|                       |         | (due to no free memory in slab)           |
| slab_reassign_chunk_rescues                                                 |
|                       | 64u     | Individual sections of an item rescued    |
|                       |         | during a page move.                       |
| slab_reassign_inline_reclaim                                                |
|                       | 64u     | Internal stat counter for when the page   |
|                       |         | mover clears memory from the chunk        |
|                       |         | freelist when it wasn't expecting to.     |
| slab_reassign_busy_items                                                    |
|                       | 64u     | Items busy during page move, requiring a  |
|                       |         | retry before page can be moved.           |
| slab_reassign_busy_deletes                                                  |
|                       | 64u     | Items busy during page move, requiring    |
|                       |         | deletion before page can be moved.        |
| log_worker_dropped    | 64u     | Logs a worker never wrote due to full buf |
| log_worker_written    | 64u     | Logs written by a worker, to be picked up |
| log_watcher_skipped   | 64u     | Logs not sent to slow watchers.           |
| log_watcher_sent      | 64u     | Logs written to watchers.                 |
|-----------------------+---------+-------------------------------------------|
                                </pre>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <a id="actions">&nbsp;</a>
        <div class="row">
            <div class="col">
                <div class="card border-primary">
                    <div class="card-header">
                        <h3 class="card-title">Get Item</h3>
                        <small class="text-muted">Get item in Memcache.</small>
                    </div>
                    <div class="card-body">
                        <form id="js-getkey-form" action="<?= $_SERVER['PHP_SELF'] ?>" method="post">
                            <div class="mb-3">
                                <label for="getKey" class="form-label">Key</label>
                                <input id="getKey" type="text" name="getkey" class="form-control" 
                                       required 
                                       maxlength="200" 
                                       value="<?= $getRequest ? htmlspecialchars($getRequest->key, ENT_NOQUOTES) : '' ?>">
                            </div>
                            <div class="mb-3 ">
                                <label class="form-label">Result</label>
                                <div class="text-break">
                                    <? if ($getRequest): ?>
                                        <? if ($getRequest->key): ?>
                                            <div class="card border-success">
                                                <div class="card-body bg-light">
                                                    <code>
                                                        <?= htmlspecialchars($getRequest->value, ENT_NOQUOTES) ?>
                                                    </code>
                                                </div>
                                            </div>
                                        <? else : ?>
                                            <div class="card bg-light">
                                                <div class="card-body">
                                                    <span class="text-muted">[ No result ]</span>
                                                </div>
                                            </div>
                                        <? endif ?>
                                    <? else : ?>
                                        <div class="card border-info bg-body-tertiary">
                                            <div class="card-body">
                                                <span class="text-muted">Enter a key above</span>
                                            </div>
                                        </div>
                                    <? endif ?>
                                </div>
                                <div id="resultHelp" class="form-text">Special chars <, > and & are encoded for security.</div>
                            </div>
                            <div class="mt-3">
                                <button type="button" id="js-clear-getkey" class="btn btn-secondary">Clear</button>
                                <button type="submit" class="btn btn-primary">Submit</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col">
                <div class="card border-primary">
                    <div class="card-header">
                        <h3 class="card-title">Set Item</h3> 
                        <small class="text-muted">Set new or overwrite existing item in Memcache.</small> 
                    </div>
                    <div class="card-body">
                        <form action="<?= $_SERVER['PHP_SELF'] ?>" method="post">
                            <div class="mb-3">
                                <label for="saveKey" class="form-label">Key</label>
                                <input id="saveKey" type="text" name="key" class="form-control" 
                                       maxlength="200" 
                                       required 
                                       pattern="[^<> '\x22`%]+" 
                                       aria-describedby="saveKeyHelp">
                                <!-- Disallow a few special chars for security and of course spaces too. -->
                                <div id="saveKeyHelp" class="form-text">Disallowed chars = <, >, ', ", `, %, and space</div>
                            </div>
                            <div class="mb-3 mt-2">
                                <label for="saveValue" class="form-label">Value</label>
                                <textarea id="saveValue" name="value" class="form-control" 
                                          required 
                                          aria-describedby="saveValueHelp"></textarea>
                                <div id="saveValueHelp" class="form-text">Stripped chars = < and ></div>
                            </div>
                            <div class="mb-3 mt-2">
                                <label for="expire" class="form-label">Expire</label>
                                <input id="expire" type="number" name="expire" class="form-control"
                                       value="7200"
                                       maxlength="15"
                                       required
                                       aria-describedby="expireHelp">
                                <div id="expireHelp" class="form-text">
                                    In seconds or Unix timestamp. Seconds cannot exceed 2592000 (30 days).
                                    <br>
                                    0 = no expiration
                                </div>
                            </div>
                            <div class="mt-3">
                                <button type="submit" class="btn btn-primary">Submit</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <a id="stored-keys">&nbsp;</a>
        <div class="card mt-4">
            <div class="card-header">
                <div class="d-flex align-items-center">
                    <h3 class="card-title me-4">"All" Stored Keys</h3>
                    <div>Total # of items in memcache = <b><?= $stats['curr_items'] ?></b></div>
                </div>
            </div>
            <div class="card-body">
                <div class="alert alert-warning" role="alert">
                    Memcache doesn't have a way to list all items. A traditional workaround is used here, but it is kludgy.
                    Sometimes the item(s) you're looking for won't appear below, but it doesn't mean it's not still in
                    Memcache. Try to refresh the page again.
                    <br>
                    What does work good is "Get item" and "Set item" above.
                </div>

                <div class="table-responsive">
                    <table id="js-data-table" class="table table-bordered table-hover table-striped"
                           style="table-layout: fixed;">
                        <thead>
                            <tr>
                                <th style="width: 20%;">key</th>
                                <th style="width: 60%;">value</th>
                                <th style="width: 10%;">expire (PST)</th>
                                <th style="width: 6%;">bytes</th>
                                <th style="width: 4%;">delete</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $i): ?>
                                <tr>
                                    <td>
                                        <span class="text-break"><?= htmlspecialchars($i->key, ENT_NOQUOTES) ?></span>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars($i->value, ENT_NOQUOTES) ?>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars($i->expire, ENT_NOQUOTES) ?>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars($i->bytes, ENT_NOQUOTES) ?>
                                    </td>
                                    <td>
                                        <form action="<?= $_SERVER['PHP_SELF'] ?>" method="post">
                                            <input type="hidden" name="del" value="<?= htmlspecialchars($i->key, ENT_NOQUOTES) ?>">
                                            <button type="submit" class="btn btn-danger">X</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
            <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
            <script src="https://cdn.datatables.net/v/dt/dt-1.13.8/datatables.min.js"></script>
            <script>
                $(function () {
                    $("#js-getkey-form").on('submit', (e) => {
                        e.preventDefault();
                        const form = document.getElementById('js-getkey-form');
                        const formData = new FormData(form);
                        const key = formData.get('getkey');
                        
                        const url = new URL(location.href);
                        const searchParams = url.searchParams;
                        searchParams.set('getkey', encodeURIComponent(key.trim()));
                        window.location.href = `${location.origin}${location.pathname}?${searchParams}`;
                    });

                    $("#js-clear-getkey").on('click', (e) => {
                        e.preventDefault();
                        const url = new URL(location.href);
                        const searchParams = url.searchParams;
                        searchParams.delete('getkey');
                        window.location.href = `${location.origin}${location.pathname}?${searchParams}`;
                    });

                    $("#js-flush-form").on('submit', (e) => {
                        if (!window.confirm('Are you sure?')) {
                            e.preventDefault();
                        }
                    });
                    
                    $('#js-change-server').on('change', (e) => {
                        $('#js-change-server-loader').removeAttr('hidden');
                        const serverIndex = e.currentTarget.value;
                        window.location.href = `${location.protocol}//${location.hostname}${location.pathname}?server=${serverIndex}`
                    });
                });
                
                // Docs = https://datatables.net/examples/basic_init/dom.html
                new DataTable('#js-data-table', {
                    stateSave: true,
                    lengthMenu: [
                        [10, 25, 50, -1],
                        [10, 25, 50, 'All']
                    ],
                    dom: '<"top"lif>rt<"bottom"p><"clear">'
                });
            </script>
</body>
</html>
