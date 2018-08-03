# BlogAutoPostFromGooglePlus
Google+からエクスポートしたjsonファイルの記事を自動的にwordpressに下書きとして投稿するスクリプト

## 使い方
1. SSLを用いる都合上、事前にクライアントの証明書とクライアントの秘密鍵とそのパスフレーズ、プライベートCAの公開鍵証明書を作成しておくこと。取得方法は[このサイト](https://blog.ashija.net/2018/08/04/post-3071/)を参照。
1. [Google データエクスポート](https://takeout.google.com/settings/takeout)を使って、Google+のデータをエクスポート。
**データはJSON形式でエクスポートすることに注意。**
1. ダウンロードしたzipを展開。
1. AutoPost.phpの「修正すべき変数」セクションの変数を適宜修正。
1. WAFに引っかかるのを防ぐため、.htaccessに以下の記述を入れる。
	```
	<IfModule mod_siteguard.c>
	  SiteGuard_User_ExcludeSig ip(このスクリプトを実行するパソコンのIPアドレス)
	</IfModule>
	```
1. AutoPost.phpを実行。

使う手順は「AutoPost.php」の最初にも書いてあります。

## 注意事項
**現状、Macでしか正常に動作しません。**
Windowsでも動くことは動きますが、波ダッシュ・全角チルダ問題に翻弄され、文字コードに起因するエラーを解消しきれませんでした。
投稿する記事の文中に波ダッシュや全角チルダがある場合は、うまくWordPressへ投稿することができない可能性が高いです。

このスクリプトはXML-RPCを利用しています。WordPressの設定で、**XML-RPCを利用できるようにしておいてください。**
プラグインによっては、ファイアウォールの設定でXML-RPCを利用できないようになっている場合があります。

また、このスクリプトでは、WordPressにおいて **Pz-LinkCard というプラグインを利用していることを前提としています。**
記事中に出てくるURLは
[bcd url="http://xxx"]
という形に括られてからWordPressに投稿されます。
