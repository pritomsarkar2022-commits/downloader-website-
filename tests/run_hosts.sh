#!/bin/bash
BASE="http://127.0.0.1:8085/index.php?r=analyze"
for u in "$@"; do
  echo "=============== $u"
  resp=$(curl -s -m 90 --data-urlencode "url=$u" "$BASE")
  echo "$resp" > tests/out/vd_resp.json
  php -r '
    $j=json_decode(file_get_contents("tests/out/vd_resp.json"),true);
    if(!$j){echo "  NOT JSON: ".substr(file_get_contents("tests/out/vd_resp.json"),0,300)."\n";exit;}
    if(empty($j["ok"])){echo "  FAIL: ".$j["error"]."\n        hint: ".($j["hint"]??"")."  [".($j["code"]??"")."]\n";exit;}
    echo "  OK provider=".$j["provider"]." title=".substr((string)$j["title"],0,40)." conf=".$j["confidence"]."\n";
    foreach($j["sources"] as $s){
      echo "   - ".str_pad($s["kind"],5)." ".str_pad((string)($s["label"]??""),18)." h=".str_pad((string)($s["height"]??"?"),5)." size=".($s["sizeHuman"]??"?")." dur=".($s["duration"]??"?")." variants=".count($s["variants"])."\n";
      foreach(array_slice($s["variants"],0,3) as $v){ echo "       q ".json_encode(["h"=>$v["height"],"bw"=>$v["bandwidth"],"seg"=>$v["segments"]??null,"enc"=>$v["encrypted"]??null,"hasStream"=>!empty($v["streamUrl"])])."\n"; }
      echo "       preview=".substr($s["previewUrl"],0,60)."...\n";
    }
  '
done
