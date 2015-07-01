#!/opt/local/bin/php55
<?php

define('DEBUG', false);
define('GH_API_URL', 'https://api.github.com');

date_default_timezone_set('America/Los_Angeles');

function main(){

    //Parse the config file
    $configFile = getcwd() . "/.gh-backup";
    if(!file_exists($configFile)){
        $configFile = getenv("HOME") . "/.gh-backup";
        if(!file_exists($configFile)){
            echoErr("Couldn't locate a github backup configuration file");
            exit(1);
        }
    }
    $configData = parseConfig($configFile);

    //Build up a list of repos to backup
    $credentials = array(
        $configData['username'],
        $configData['token']
    );
    $repoList = array();
    $repoList = array_merge(
        $repoList,
        myRepoList($credentials),
        orgsRepoList($credentials)
    );
    if(isset($configData['filter'])){
        $filter = $configData['filter'];
        foreach($repoList as $index => $repo){
            if(preg_match($filter, $repo) == 0){
                unset($repoList[$index]);
            }
        }
    }
    $repoList = array_unique($repoList);
    $repoList = array_values($repoList); # Re-index

    $instanceBackupDir = $configData['directory'] . "/" . date('Y-m-d');
    exec("mkdir -p {$instanceBackupDir}");
    chdir($instanceBackupDir);
    foreach($repoList as $repoUrl){
        preg_match('/:(.*)\//', $repoUrl, $matches);
        $entity = $matches[1]; # User or Org
        $entityBackupDir = $instanceBackupDir . "/" . $entity;
        exec("mkdir -p {$entityBackupDir}");
        chdir($entityBackupDir);
        exec("git clone --mirror {$repoUrl}");
    }
}

function orgsRepoList($credentials){

    $repoUrlList = array();

    $orgGenerator = apiAllResults($credentials, '/user/memberships/orgs');
    foreach($orgGenerator as $orgMembership){
        $orgRepoUrl = implode('/', array(
            '/orgs',
            $orgMembership['organization']['login'],
            'repos'
        ));
        $repoGenerator = apiAllResults($credentials, $orgRepoUrl);
        foreach($repoGenerator as $repo){
            $repoUrlList[] = $repo['ssh_url'];
        }
    }

    return $repoUrlList;
}

function myRepoList($credentials){

    $repoUrlList = array();
    $nextPage = false;

    $generator = apiAllResults($credentials, '/user/repos');
    foreach($generator as $repo){
        $repoUrlList[] = $repo['ssh_url'];
    }

    return $repoUrlList;
}

function apiAllResults($credentials, $relativePath, $method='GET', $data=false){

    $nextPage = $relativePath;

    do {

        list($response, $headers) = apiRequest($credentials, $nextPage, $method, $data);
        foreach($response as $result)
            yield $result;

        $nextPage = false;
        if(isset($headers['Link']) && !empty($headers['Link'])){
            $links = explode(',', $headers['Link']);
            foreach($links as $linkData){
                list($link, $rel) = array_map('trim', explode(';', $linkData, 2));
                if($rel == 'rel="next"'){
                    $nextPage = trim($link, ' <>');
                }
            }
        }

    } while($nextPage !== false);

}

function apiRequest($credentials, $url, $method='GET', $data=false){

    if(preg_match('/^http(s)?:\/\//', $url) == 0)
        $url = GH_API_URL . $url;

    $headers = array(
        'User-Agent: github-backup',
        'Content-Type: application/json',
        'Authorization: Basic ' . base64_encode($credentials[0] . ":" . $credentials[1])
    );
    if($data !== false){
        $data = json_encode($data);
    }

    list($response, $code, $headers) = curlRequest($url, $method, $data, $headers);
    if($code > 300)
        throw new Exception($response);

    return array(
        json_decode($response, true),
        $headers
    );
}

function curlRequest($url, $method='GET', $data=false, $requestHeaders=array()){

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    if($data !== false)
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $requestHeaders);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HEADER, 1);
    if(DEBUG)
        curl_setopt($ch, CURLOPT_VERBOSE, true);
    $response = curl_exec($ch);
    $responseCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    list($rawResponseHeaders, $responseBody) = explode("\r\n\r\n", $response, 2);
    $rawResponseHeaders = explode("\r\n", $rawResponseHeaders);
    $responseHeaders = array();
    foreach($rawResponseHeaders as $header){
        list($key, $value) = array_map('trim', explode(':', $header, 2));
        $responseHeaders[$key] = $value;
    }
    return array($responseBody, $responseCode, $responseHeaders);
}

function parseConfig($configFile){

    $configData = json_decode(file_get_contents($configFile), true);
    if($configData == null){
        echoErr("Couldn't parse configuration file");
        exit(1);
    }

    if(!isset($configData['username']) || empty($configData['username'])){
        echoErr("Configuration error - a username is required");
        exit(1);
    }
    if(!isset($configData['token']) || empty($configData['token'])){
        echoErr("Configuration error - a token is required");
        exit(1);
    }

    if(!isset($configData['directory']) || empty($configData['directory'])){
        echoErr("Configuration error - a backup directory must be specified");
        exit(1);
    }
    if(strpos($configData['directory'],'/') != 0){
        $configData['directory'] = getcwd() . '/' . $configData['directory'];
    }

    return $configData;
}

function echoErr($msg){

    file_put_contents('php://stderr', $msg . "\n");
}

main();
