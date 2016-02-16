<?php

require __DIR__ . '/vendor/autoload.php';

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;


$dotenv = new Dotenv\Dotenv(__DIR__);
$dotenv->load();

$request = Request::createFromGlobals();

if (is_valid($request)) {
	sendHipchatMessage($request);
}

/**
 * Verification request + calcul signature
 */
function is_valid($request)
{
	$payload = $request->getContent();

	if (empty($payload)) {
		returnResponse('empty payload', 400);
		return false;
	}

	//Bignou calcul signature Gihub
	if (!isGithubSignatureValid($request)) {
		returnResponse('invalid github signature', 400);
		return false;
	}

	//verif valid payload
	if (null === json_decode($payload)) {
		returnResponse('invalid json body', 400);
		return false;
	}

	return true;
}

function sendHipchatMessage(Request $request)
{
	$eventType = $request->headers->get('X-GitHub-Event');
	$payload = json_decode($request->getContent(), true);

	$data                    = [];
    $data['comment_login']   = $payload['comment']['user']['login'];
    $data['comment_avatar']  = $payload['comment']['user']['avatar_url'];
    $data['repo_fullname']   = $payload['repository']['full_name'];
    $data['comment_message'] = $payload['comment']['body'];
    $data['comment_link']    = $payload['comment']['html_url'];

    list($organisation, $repo) = explode('/', $data['repo_fullname']);

	
    switch ($eventType) {
    	case 'commit_comment':
    		$commit = getCommitData($organisation, $repo, $payload['comment']['commit_id']);
		    
		    $data['commit_author']  = $commit['author']['login'];
    		$data['commit_message'] = $commit['commit']['message'];
    		break;
    	case 'issue_comment':
	    	$data['commit_author']  = $payload['issue']['user']['login'];
    		$data['commit_message'] = $payload['issue']['title'];
    		break;
    }

    doSendHipchatMessage($data);
}

function getCommitData($organisation, $repo, $commitId)
{
	$githubClient = getGithubClient();
    return $githubClient
    	->api('repo')
    	->commits()
    	->show(
    		$organisation, 
    		$repo, 
    		$commitId
    	);
}


function doSendHipchatMessage($datas)
{
	$map = json_decode(getenv("HIPCHAT_USERS_MAP"), true);
	$url = sprintf(
		'%s/user/%s/message?auth_token=%s', 
		getenv('HIPCHAT_ENDPOINT'), 
		$map[$datas['commit_author']], 
		getenv('HIPCHAT_TOKEN')
	);

	$message = sprintf(
            '<img src="%s" width="25px"> <b>%s</b> a laissé un commentaire<br />
            <a href="%s">%s</a><br />Message : %s',
            $datas['comment_avatar'],
            $datas['comment_login'],
            $datas['comment_link'],
            $datas['commit_message'],
            $datas['comment_message']);

	$json_datas = [
		'message'        => $message,
        'notify'         => true,
        'message_format' => 'html'
	];

	$client   = new \GuzzleHttp\Client();
	$response = $client->request('POST', $url, ['json' => $json_datas]);
}


function getGithubClient()
{
	$token = getenv('GITHUB_TOKEN');

	$client = new \Github\Client();
	$client->authenticate($token, $token, Github\Client::AUTH_HTTP_TOKEN);

	return $client;
}

function returnResponse($content, $code = 200)
{
	$response = new Response($content, $code);
	$response->send();
}

function isGithubSignatureValid(Request $request)
{
	$their = $request->headers->get('X-Hub-Signature');

	list($algo, $their_hash) = explode('=', $their, 2);

	$payload  = $request->getContent();
	$our_hash = hash_hmac($algo, $payload, getenv('GITHUB_SECRET'));

	return $their_hash === $our_hash;
}