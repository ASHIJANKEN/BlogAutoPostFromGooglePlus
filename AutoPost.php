<?php
////////////////////////////////////////////////////////////////////////////
// Macでこのphpを実行することを推奨。
// 1. アーカイブを展開。
// 2. 29行目からの修正すべき変数を適宜修正。
// 4. このphpファイルを実行。
//
// ======以下の手順はWindowsでの方法で、しかも失敗する可能性が高いです!!!======
//
// このスクリプトを走らせる前の準備
// 1. ダウンロードしたアーカイブファイルをとりあえず何でもいいので展開
// 2. 展開したフォルダを7-zipにてパラメータを「cu=on」にして圧縮
// 3. それをwindows標準の機能で展開(右クリック→すべて展開)。展開したフォルダが文字化けしていれば成功。
// 4. 29行目からの修正すべき変数を適宜修正。
//    パスの記述をMacに合わせているので、さらにWindowsに合わせるために83行目の「 "/" . 」を消す。
// 5. このphpファイルを実行。
////////////////////////////////////////////////////////////////////////////

require_once "IXR_Library.php";

///////////////////////修正すべき変数ここから///////////////////////////////

$json_path = '/Users/user/Downloads/Takeout/Google+ ストリーム'; //「Google+ ストリーム」フォルダのパス
$xmlrpc_path = "http://wordpressblog.com/xmlrpc.php"; //サーバー上のxmlrpc.phpのパス
$user = 'user'; // ユーザー名
$pass = 'password'; // パスワード

////////////////////////修正すべき変数ここまで//////////////////////////////


// 履歴記録用のファイル
$hist_file = __DIR__ . '/posted.txt';

function format_time($raw_time_str){
  // $raw_time_strの例
  // 2014-02-19T11:11:38.000Z

  //時差
  // $time_difference = 9;

  //繰り上がりがあるかどうか
  $carry = false;

  // 年月日と時間を分割
  $split = explode('T', $raw_time_str);

  // 時刻部分の整形
  $time_split = explode('.', $split[1]);
  $time_split = explode(':', $time_split[0]);
  $hour = $time_split[0];
  // $hour = $time_split[0] - $time_difference;
  // if($hour <= 0){
  //   $hour += 24;
  //   $carry = true;
  // }
  $time_str = $hour . ':' . $time_split[1] . ':' . $time_split[2];

  //年月日部分の整形
  $date_str = str_replace('-', '/', $split[0]);
  if($carry == true){
    // 日付の繰り上げ
    $date_str = date("Y-m-d", strtotime("$date_str -1 day"));
  }else{
    $date_str = str_replace('/', '-', $split[0]);
  }

  // $time_strの例
  // 2014-2-19 11:11:38
  return $date_str . ' ' . $time_str;
}

function get_data($post_data_dir){
  global $hist_file;
  $post_data_list = array();
  $export_json_list = array();

  // ポストしたデータの履歴データを開く
  $post_hist_id = file($hist_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

  if( is_dir( $post_data_dir ) && $handle = opendir( $post_data_dir ) ) {
		while( ($file = readdir($handle)) !== false ) {
			if( filetype( $post_path = $post_data_dir . "/" . $file ) == "file" ) {
        // ファイルを読み込んで連想配列を取得
        $json_data_raw = file_get_contents($post_path);
        $json_data_converted = mb_convert_encoding($json_data_raw, 'UTF-8');
        $json_data = json_decode($json_data_converted, true);

        // idを確認して、すでに投稿済みならスキップ
        if(in_array($json_data['id'], $post_hist_id) == False){
          // タイトルは基本的にjsonから取得
          $title = (strcmp($json_data['title'], '') == 0) ? $file : $json_data['title'];

          // 時刻文字列の整形
          $published_date_gmt = format_time($json_data['published']);
          $updated_date = format_time($json_data['updated']);

          // attachmentがないときもあることを考慮して取得
          $attachments = (array_key_exists('attachments', $json_data['object']))
                        ? '[bcd url="' . $json_data['object']['attachments'][0]['url'] . '"]'
                        : '';

          $content = $json_data['object']['content'];
          // contentの最後の'?'を除去し(しなくても良いっぽい)
          // $content = rtrim($content, '?');
          //contentにあるaタグを抽出し、bcdタグで囲い直す
          $content = preg_replace('/<a href=((?!>).)*>/', '[bcd url="', $content);
          $content = str_replace('</a>', '"]', $content);

          // コメントは全部'<br />'でつないで取得
          $comments = '';
          for($i = 0; $i < $json_data['object']['replies']['totalItems']; $i++){
            $item = $json_data['object']['replies']['items'][$i]['object'];
            $comments = $comments . '<br />' . $item['content'];
          }

          // contentにアタッチメントとコメントを統合
          $content = $content . '<br /><br />' . $attachments . '<br /><br />' . $comments;

          $post_info = array(
            'title' => $title,
            'published_date' => $published_date_gmt,
            'updated_date' => $updated_date,
            'attachment_url' => $attachments,
            'content' => $content,
            'comment' => $comments,
            'id' => $json_data['id'],
          );

          // リストに追加
          $post_data_list[] = $post_info;
        }
			}
		}
	}
  return $post_data_list;
}

function post_2_blog($post_data_list){
  global $hist_file;
  global $xmlrpc_path;
  global $user;
  global $pass;

  foreach ($post_data_list as $post_data) {
    $client = new IXR_Client($xmlrpc_path);
    $status = $client->query(
      "wp.newPost", //使うAPIを指定
      1,     // blog ID: 通常は１
      $user, // ユーザー名
      $pass, // パスワード
      array(
        'post_author' => $user,     // 投稿者ID 未設定の場合投稿者名なしになる。
        'post_status' => 'draft', // 投稿状態（draftにすると下書きにできる）
        'post_title'   => $post_data['title'],   // タイトル
        'post_content' => $post_data['content'],      //　本文
	'post_date_gmt'      => $post_data['published_date'], // 投稿の作成日時。
	'comment_status' => 'open', //コメントを許可
      )
    );

    if(!$status){
    	// 投稿失敗時に、エラーメッセージ・コードを出力
      	echo $client->getErrorCode().' : '.$client->getErrorMessage() . "]\n";
        echo 'Post failed : ' . $post_data['title'] . "\n";
    } else {
     	$postid = $client->getResponse(); // 戻り値は投稿ID
      echo 'Successfully posted : ' . $post_data['title'] . "\n";

      // txtファイルに書き込む
      $export = fopen($hist_file, 'a+b');
      fwrite($export, $post_data['id'] . "\n");
      fclose($export);
    }
  }
}

function main(){
  $post_data_dir = $json_path;
  // $post_data_dir = mb_convert_encoding($post_data_dir, 'UTF-8');

  echo "Start fetching...\n";

  // jsonデータから投稿データを取得
  $post_data_list = get_data($post_data_dir);
  // var_dump($post_data_list);

  echo "Start posting...\n";

  // wordpressに投稿
  post_2_blog($post_data_list);

  echo "Done.\n";

}

main();
?>
