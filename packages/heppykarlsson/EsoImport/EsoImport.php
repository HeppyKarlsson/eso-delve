<?php namespace HeppyKarlsson\EsoImport;


use App\Enum\BagType;
use App\Enum\CraftingType;
use App\Enum\ItemStyleChapter;
use App\Model\Character;
use App\Model\CharacterItemStyle;
use App\Model\CraftingTrait;
use App\Model\Item;
use App\Model\ItemStyle;
use App\Model\UserItem;
use Carbon\Carbon;
use HeppyKarlsson\Meta\Service\MetaService;
use Illuminate\Support\Facades\Auth;

class EsoImport
{

    private $characters = [];
    private $items = null;

    public function import($file_path) {
        set_time_limit(120);
        UserItem::where('userId', Auth::id())->delete();
        echo "Importing<br>";
        $file = fopen($file_path, 'r');

        $user = Auth::user();
        $user->dumpUploaded_at = Carbon::now();
        $user->save();
        
        $this->items = Item::all()->keyBy('itemLink');

        $lines = [];

        if($file) {
            $line = null;
            while (!feof($file))
            {
                $lines[] = fgets($file);
            }
            fclose($file);
        }
        else {
            throw new \Exception('File couldnt be opened');
        }

        foreach($lines as $line) {
            $this->importStyles($line);
        }

        foreach($lines as $line) {
            $this->importCharacter($line);
        }

        Auth::user()->load('characters.craftingTraits');

        foreach($lines as $line) {
            $this->importSmithing($line);
        }

        foreach($lines as $line) {
            $this->importItem($line);
        }


        $this->cleanCharacters();

    }

    public function importStyles($line) {
        if(stripos($line, 'ITEMSTYLE:') === false) {
            return false;
        }

        $data = explode(';', $line);
        $externalId = intval($data[5]);
        $itemStyle = ItemStyle::where('externalId', $externalId)->first();

        if(is_null($itemStyle)) {
            $itemStyle = new ItemStyle();
            $itemStyle->externalId = $externalId;
            $itemStyle->name = '';
            $itemStyle->image = $data[4];
            $itemStyle->material = $data[3];
            $itemStyle->save();
        }

        $character = Character::where('externalId', $data[1])->first();
        if(is_null($character)) {
            return true;
        }

        $chapterKnown = explode('-', $data[7]);
        $chapters = ItemStyleChapter::order();

        foreach($chapterKnown as $key => $known) {
            if(stripos($known, 'true') !== false) {
                $characterItemStyle = CharacterItemStyle::firstOrNew([
                    'characterId' => $character->id,
                    'itemStyleId' => $itemStyle->id,
                    'itemStyleChapterEnum' => $chapters[$key],
                    'isKnown' => 1,
                ]);

                $characterItemStyle->save();
            }
        }
    }

    public function importSmithing($line) {
        if(stripos($line, 'SMITHING:') === false) {
            return false;
        }

        $info = explode(';', $line);
        $character = Auth::user()->characters->where('externalId', $info[1])->first();
        $smithingType = intval($info[3]);

        $craftingTrait = $character->craftingTraits->where('craftingTypeEnum', $smithingType)
            ->where('traitId', intval($info[7]))
            ->where('researchLineIndex', intval($info[4]))
            ->where('traitIndex', intval($info[5]))
            ->first();


        if(is_null($craftingTrait)) {
            $craftingTrait = new CraftingTrait();
            $craftingTrait->characterId = $character->id;
            $craftingTrait->craftingTypeEnum = $smithingType;
            $craftingTrait->traitId = intval($info[7]);
            $craftingTrait->researchLineIndex = intval($info[4]);
            $craftingTrait->traitIndex = intval($info[5]);
            $craftingTrait->name = $info[10];
            $craftingTrait->image = $info[11];
        }

        if(isset($info[13]) and $info[2] !== 'nil') {
            $researchDone = intval($info[13]) + intval($info[2]);
            $craftingTrait->researchDone_at = Carbon::createFromTimestamp($researchDone);
        }

        $craftingTrait->isKnown = stripos($info[9], 'true') !== false;
        $craftingTrait->save();

    }

    public function createMeta($meta, $character, $value) {

    }

    public function importCharacter($line) {
        $item_start = stripos($line, 'CHARACTER:');
        if($item_start === false) {
            return false;
        }

        $line = str_ireplace('",', '', $line);
        $line = substr($line, $item_start + 10);

        $properties = explode(';', $line);

        $character = Auth::user()->characters()->withTrashed()->where('externalId', $properties[0])->first();

        if(!$character) {
            $character = new Character();
        }

        $character->name = $properties[1];
        $character->externalId = $properties[0];
        $character->classId = $properties[3];
        $character->level = $properties[4];
        $character->championLevel = $properties[5];
        $character->raceId = $properties[7];
        $character->allianceId = $properties[8];
        $character->userId = Auth::user()->id;
        $character->deleted_at = null;
        $character->currency = intval($properties[12]);

        if(isset($properties[13])) {
            $smithingSkills = explode('-', $properties[13]);

            $metaService = new MetaService();
            $metaService->update($character, 'max_smithing_' . CraftingType::BLACKSMITHING, intval($smithingSkills[0]));
            $metaService->update($character, 'max_smithing_' . CraftingType::CLOTHIER, intval($smithingSkills[1]));
            $metaService->update($character, 'max_smithing_' . CraftingType::WOODWORKING, intval($smithingSkills[2]));
        }

        if(isset($properties[11])) {
            $roles = explode('-', $properties[11]);
            $character->isDPS = $roles[0] == 'true';
            $character->isHealer = $roles[1] == 'true';
            $character->isTank = $roles[2] == 'true';
        }

        $character->ridingUnlocked_at = null;
        if(isset($properties[9])) {
            // Calculate when next riding lesson is unlocked
            $properties = explode(';', $line);
            $seconds = intval($properties[9]) / 1000;
            $nextTraining = intval($properties[10]) + $seconds;
            $character->ridingUnlocked_at = $nextTraining;
        }

        $character->save();

        $this->characters[] = $character->externalId;
    }

    public function cleanCharacters() {
        Auth::user()->characters()->where('userId', Auth::id())->whereNotIn('externalId', $this->characters)->delete();
    }

    public function importItem($line) {
        $item_start = stripos($line, 'ITEM:');
        if($item_start === false) {
            return false;
        }

        $line = str_ireplace('",', '', $line);
        $line = substr($line, $item_start + 5);

        $properties = explode(';', $line);

        $bagType = isset($properties[15]) ? intval($properties[15]) : null;

        $character = Auth::user()->characters()->where('externalId', intval($properties[14]))->first();
              
        if(isset($bagType) and $bagType === BagType::BANK) {
            $character = null;
        }


        if(isset($properties[23])) {
            $item = Item::where('name', trim($properties[1]))
                ->where('trait', intval($properties[2]))
                ->where('quality', intval($properties[5]))
                ->where('equipType', intval($properties[3]))
                ->where('armorType', intval($properties[6]))
                ->where('type', intval($properties[10]))
                ->where('weaponType', intval($properties[13]))
                ->where('enchant', trim($properties[8]))
                ->where('itemValue', intval($properties[22]))
                ->where('level', intval($properties[12]))
                ->where('championLevel', intval($properties[11]))
                ->first();

            if(!$item) {
                $item = new Item();
                $item->uniqueId = $properties[0];
                $item->name = trim($properties[1]);
                $item->equipType = intval($properties[3]);
                $item->armorType = intval($properties[6]);
                $item->quality = intval($properties[5]);
                $item->icon = $properties[9];
                $item->type = intval($properties[10]);
                $item->championLevel = intval($properties[11]);
                $item->level = intval($properties[12]);
                $item->weaponType = intval($properties[13]);
                $item->itemLink = $properties[19];
                $item->trait = $properties[2];
                $item->traitDescription = $properties[21];
                $item->enchant = $properties[8];
                $item->enchantDescription = $properties[20];
                $item->itemValue = intval($properties[22]);

                if(!empty($properties[4])) {
                    $item->setItemSet($properties[4]);
                }

                if(isset($properties[25]) and intval($properties[25]) != 0) {
                    $itemStyle = ItemStyle::where('externalId', intval($properties[25]))->first();
                    $item->itemStyleId = isset($itemStyle->id) ? $itemStyle->id : null;
                }

                $item->save();
            }

            if($item) {
                $userItem = new UserItem();
                $userItem->userId = Auth::id();
                $userItem->itemId = $item->id;
                $userItem->characterId = $character ? $character->id : null;
                $userItem->uniqueId = $properties[0];
                $userItem->traitEnum = $properties[2];
                $userItem->traitDescription = $properties[21];
                $userItem->enchant = $properties[8];
                $userItem->enchantDescription = $properties[20];
                $userItem->bagEnum = $bagType;
                $userItem->slotId = intval($properties[23]);

                if(isset($properties[25]) and intval($properties[25]) != 0) {
                    $itemStyle = ItemStyle::where('externalId', intval($properties[25]))->first();
                    $userItem->itemStyleId = isset($itemStyle->id) ? $itemStyle->id : null;
                }

                $userItem->equipTypeEnum = $properties[3];
                $userItem->armorTypeEnum = $properties[6];
                $userItem->weaponTypeEnum = intval($properties[13]);
                $userItem->count = intval($properties[17]);

                $userItem->isBound = (isset($properties[16]) and stripos($properties[16], 'true') !== false);
                $userItem->isLocked = $properties[7] == 'true';

                $userItem->save();

            }
        }
    }
}
