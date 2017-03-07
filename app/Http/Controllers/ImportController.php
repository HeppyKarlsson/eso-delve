<?php namespace App\Http\Controllers;

use App\Model\Dungeon;
use App\Model\DungeonSet;
use App\Model\Set;
use App\Model\SetBonus;
use App\Model\UserSetFavourite;
use HeppyKarlsson\EsoImport\EsoImport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class ImportController
{

    public function import(EsoImport $esoImport) {

//        $content = file_get_contents(storage_path('killCounter.json'));
        $content = file_get_contents(storage_path('MM00Data.lua'));
        $content = str_replace('["', '"', $content);
        $content = str_replace('"]', '"', $content);
        $content = str_replace('[', '"', $content);
        $content = str_replace(']', '"', $content);
        $content = str_replace(' =', ': ', $content);
        $content = trim(preg_replace('/\s\s+/', ' ', $content));
        $content = str_ireplace(', }', ' }', $content);
        $json = json_decode($content, true);
        dd(array_chunk($json['Default']['MasterMerchant']['$AccountWide']['SalesData'], 50)[0]);

        $path = storage_path('TesoDelve.lua');
        $return = $esoImport->jobImport($path);
//        $return = $esoImport->import($path);
        return 'works';
    }

    public function export() {
        $sets = Set::all();
        $setBonuses = SetBonus::all();
        $userFavourites = UserSetFavourite::all();
        $dungeons = Dungeon::all();
        $dungeonSets = DungeonSet::all();

        file_put_contents(storage_path('dump/sets.json'), $sets->toJson());
        file_put_contents(storage_path('dump/setBonuses.json'), $setBonuses->toJson());
        file_put_contents(storage_path('dump/userFavourites.json'), $userFavourites->toJson());
        file_put_contents(storage_path('dump/dungeons.json'), $dungeons->toJson());
        file_put_contents(storage_path('dump/dungeonSets.json'), $dungeonSets->toJson());

        return 'success';
    }

    public function upload(Request $request, EsoImport $esoImport) {
        $files = $request->files->all();

        foreach($files as $file) {
            /** @var $file UploadedFile */
            if($file->getClientOriginalName() == 'TesoDelve.lua') {
                File::copy($file->getRealPath(), storage_path('dumps/dump_' . Auth::id() . ".lua"));
                return $esoImport->import($file->getRealPath());
            }

            abort(404);
        }
    }

    public function index() {
        $addonInfo = Cache::remember('github_addon_version', config('addon.github.cache-time'), function() {
            $opts = config('addon.github.opts');
            $context = stream_context_create($opts);

            $result = file_get_contents(config('addon.github.repo-url'), null, $context);
            $result = json_decode($result);

            return [
                'version' => $result->tag_name,
                'zipball' => $result->zipball_url,
            ];
        });

        return view('import.index', compact('addonInfo'));
    }

}