<?php
declare(strict_types=1);
const SESSION_LIFETIME = 7200;
function startAuthSession(): void { if(session_status()===PHP_SESSION_ACTIVE)return;ini_set('session.gc_maxlifetime',(string)SESSION_LIFETIME);session_set_cookie_params(['lifetime'=>SESSION_LIFETIME,'path'=>'/','httponly'=>true,'samesite'=>'Lax','secure'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off']);session_start(); }
function isAuthenticated(): bool { startAuthSession();if(empty($_SESSION['user_id'])||empty($_SESSION['expires_at']))return false;if(time()>(int)$_SESSION['expires_at']){logoutUser();return false;}return true; }
function requirePageAuth(): void { if(!isAuthenticated()){header('Location: login.php');exit;} }
function requireApiAuth(): void { if(!isAuthenticated()){http_response_code(401);header('Content-Type: application/json');echo json_encode(['error'=>'Sessionen har gått ut']);exit;} }
function loginUser(array $user): void { startAuthSession();session_regenerate_id(true);$_SESSION['user_id']=(int)$user['id'];$_SESSION['username']=$user['username'];$_SESSION['expires_at']=time()+SESSION_LIFETIME; }
function logoutUser(): void { startAuthSession();$_SESSION=[];if(ini_get('session.use_cookies')){$p=session_get_cookie_params();setcookie(session_name(),'',time()-42000,$p['path'],$p['domain'],$p['secure'],$p['httponly']);}session_destroy(); }
