<?php namespace HeppyKarlsson\EsoImport;

use App\Model\UserItem;
use App\User;
use Carbon\Carbon;
use HeppyKarlsson\EsoImport\Exception\DumpValidation;
use Illuminate\Support\Facades\Auth;

class EsoImport
{
    /** @var User */
    private $user = null;

    public function import($file_path) {
        set_time_limit(120);
        UserItem::where('userId', Auth::id())->delete();

        $user = Auth::user();
        $this->user = $user;
        $user->dumpUploaded_at = Carbon::now();
        $user->save();

        try {
            File::eachRow($file_path, function($line) use($user) {
                $this->checkFile($line);

                $this->setUserLang($line);
                if(strpos($line, 'CHARACTER:') !== false) {
                    $characterImport = new Import\Character();
                    $characterImport->process($line, $user);
                }

            });

            $user->load('characters');
            $user->load('characters.craftingTraits');

            File::eachRow($file_path, function($line) use($user) {

                if (strpos($line, 'ITEMSTYLE:') !== false) {
                    $itemStyleImport = new Import\ItemStyle();
                    $itemStyleImport->process($line, $user);
                }

                if (strpos($line, 'SMITHING:') !== false) {
                    $smithingImport = new Import\Smithing();
                    $smithingImport->process($line, $user);
                }

                if (strpos($line, 'ITEM:') !== false) {
                    $itemImport = new Import\Item();
                    $itemImport->process($line, $user);
                }

            });

        } catch(DumpValidation $e) {
            return response()->json(['upload' => 'error', 'error' => $e->getMessage()], 400);
        }

        return response()->json(['upload' => 'success']);
    }

    public function checkFile($line) {
        if(stripos($line, 'local function loadTesoDelve(') !== false) {
            throw new DumpValidation('Import failed, it looks like you have accidently uploaded the TesoDelve file from Addons folder instead of SavedVariables');
        }
    }

    public function setUserLang($line) {
        $item_start = strpos($line, 'CHARACTER:');
        if($item_start === false) {
            return false;
        }

        $line = str_ireplace('",', '', $line);
        $line = substr($line, $item_start + 10);
        $properties = explode(';', $line);

        $lang = isset($properties[18]) ? trim(preg_replace('/\s\s+/', ' ', $properties[18])) : config('constants.default-language');
        if($this->user->lang != $lang) {
            $this->user->lang = $lang;
            $this->user->save();
        }
    }
}
