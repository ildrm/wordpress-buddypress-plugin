<?php
$root=dirname(__DIR__);
$messages=[];
$functions=['__','_e','esc_html__','esc_html_e','esc_attr__','esc_attr_e'];
foreach(array_merge(glob($root.'/src/*.php'),glob($root.'/*.php')) as $file) {
    $tokens=token_get_all(file_get_contents($file));
    for($index=0;$index<count($tokens);++$index) {
        $token=$tokens[$index];
        if(!is_array($token)||$token[0]!==T_STRING||!in_array($token[1],$functions,true)) continue;
        $cursor=$index+1;
        while(isset($tokens[$cursor])&&is_array($tokens[$cursor])&&$tokens[$cursor][0]===T_WHITESPACE) ++$cursor;
        if(($tokens[$cursor++]??null)!=='(') continue;
        while(isset($tokens[$cursor])&&is_array($tokens[$cursor])&&$tokens[$cursor][0]===T_WHITESPACE) ++$cursor;
        $literal=$tokens[$cursor]??null;
        if(!is_array($literal)||$literal[0]!==T_CONSTANT_ENCAPSED_STRING) continue;
        $text=substr($literal[1],1,-1);
        $text=$literal[1][0]==="'" ? str_replace(["\\'","\\\\"],["'","\\"],$text) : stripcslashes($text);
        $messages[$text][]=str_replace('\\','/',substr($file,strlen($root)+1)).':'.$token[2];
    }
}
foreach(glob($root.'/assets/*.js') as $file) {
    preg_match_all('/\b__\(\s*([\'\"])((?:\\\\.|(?!\1).)*)\1\s*,\s*[\'\"]buddypress-intelligence[\'\"]\s*\)/u',file_get_contents($file),$matches,PREG_OFFSET_CAPTURE);
    foreach($matches[2] as [$text,$offset]) {
        $messages[str_replace(["\\'",'\\"'],["'",'"'],$text)][]='assets/'.basename($file).':'.(substr_count(substr(file_get_contents($file),0,$offset),"\n")+1);
    }
}
ksort($messages);
$pot="msgid \"\"\nmsgstr \"\"\n\"Project-Id-Version: BuddyPress Intelligence 1.0.0\\n\"\n\"MIME-Version: 1.0\\n\"\n\"Content-Type: text/plain; charset=UTF-8\\n\"\n\"Content-Transfer-Encoding: 8bit\\n\"\n\"X-Domain: buddypress-intelligence\\n\"\n\"Plural-Forms: nplurals=2; plural=(n != 1);\\n\"\n\n";
foreach($messages as $message=>$references) {
    $pot.='#: '.implode(' ',array_unique($references))."\nmsgid ".json_encode($message,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\nmsgstr \"\"\n\n";
}
if(!is_dir($root.'/languages')) mkdir($root.'/languages');
file_put_contents($root.'/languages/buddypress-intelligence.pot',$pot);
echo count($messages)." translation messages extracted.\n";
