<?php
namespace FleetApi;

class JsonDecodeException extends \RuntimeException {}

// PHP JSON semantics, with duplicate DECODED object keys rejected.
// No file/network access, text normalization or physical/numeric conversion.
class StrictJson
{
    public static function decode($json,$associative=true,$depth=64)
    {
        if(!is_string($json) || !is_bool($associative) || !is_int($depth) || $depth<1 || $depth>128){throw new JsonDecodeException('json_invalid');}
        $value=json_decode($json,$associative,$depth);
        if(json_last_error()!==JSON_ERROR_NONE){throw new JsonDecodeException('json_invalid');}
        (new JsonKeyScanner($json,$depth))->verify();
        return $value;
    }
}

// Native JSON validation is supplemented with UTF-16 escape-pair validation:
// PHP5.6 may silently accept an unpaired surrogate. Only the keys of the current
// object are recorded; a second decoded document is not built.
class JsonKeyScanner
{
    private $text;
    private $at=0;
    private $depth;
    public function __construct($text,$depth){$this->text=$text;$this->depth=$depth;}
    private function ws(){while(isset($this->text[$this->at]) && strpos(" \t\r\n",$this->text[$this->at])!==false){$this->at++;}}
    private function expect($char){$this->ws();if(!isset($this->text[$this->at]) || $this->text[$this->at++]!==$char){throw new JsonDecodeException('json_invalid');}}
    private function stringToken()
    {
        $this->ws();$start=$this->at;$this->expect('"');
        while(true){
            $end=strpos($this->text,'"',$this->at);
            if($end===false){throw new JsonDecodeException('json_invalid');}
            $slashes=0;for($i=$end-1;$i>$start && $this->text[$i]==='\\';$i--){$slashes++;}
            $this->at=$end+1;
            if($slashes%2===0){$token=substr($this->text,$start,$this->at-$start);$this->validateUnicodeEscapes($token);return $token;}
        }
    }
    private function validateUnicodeEscapes($token)
    {
        $at=1;$end=strlen($token)-1;
        while(($slash=strpos($token,'\\',$at))!==false && $slash<$end){
            // Escaped backslashes are skipped as a pair, not treated as \u.
            if($token[$slash+1]!=='u'){$at=$slash+2;continue;}
            $unit=hexdec(substr($token,$slash+2,4));
            if($unit>=0xD800 && $unit<=0xDBFF){
                if(substr($token,$slash+6,2)!=='\\u'){throw new JsonDecodeException('json_invalid');}
                $low=hexdec(substr($token,$slash+8,4));
                if($low<0xDC00 || $low>0xDFFF){throw new JsonDecodeException('json_invalid');}
                $at=$slash+12;
            }else{
                if($unit>=0xDC00 && $unit<=0xDFFF){throw new JsonDecodeException('json_invalid');}
                $at=$slash+6;
            }
        }
    }
    private function value($level)
    {
        if($level>$this->depth){throw new JsonDecodeException('json_invalid');}
        $this->ws();if(!isset($this->text[$this->at])){throw new JsonDecodeException('json_invalid');}
        $char=$this->text[$this->at];
        if($char==='"'){$this->stringToken();return;}
        if($char==='{'){
            $this->at++;$this->ws();$seen=array();
            if(isset($this->text[$this->at]) && $this->text[$this->at]==='}'){$this->at++;return;}
            while(true){
                $key=json_decode($this->stringToken(),true);
                if(json_last_error()!==JSON_ERROR_NONE || !is_string($key)){throw new JsonDecodeException('json_invalid');}
                // Preserve distinct string names such as "01" and "1".
                $tag='key:'.$key;if(isset($seen[$tag])){throw new JsonDecodeException('json_duplicate_key');}$seen[$tag]=true;
                $this->expect(':');$this->value($level+1);$this->ws();
                if(isset($this->text[$this->at]) && $this->text[$this->at]==='}'){$this->at++;return;}
                $this->expect(',');
            }
        }
        if($char==='['){
            $this->at++;$this->ws();
            if(isset($this->text[$this->at]) && $this->text[$this->at]===']'){$this->at++;return;}
            while(true){$this->value($level+1);$this->ws();if(isset($this->text[$this->at]) && $this->text[$this->at]===']'){$this->at++;return;}$this->expect(',');}
        }
        $span=strcspn($this->text," \t\r\n,]}",$this->at);
        if($span<1){throw new JsonDecodeException('json_invalid');}$this->at+=$span;
    }
    public function verify(){ $this->value(0);$this->ws();if($this->at!==strlen($this->text)){throw new JsonDecodeException('json_invalid');} }
}
