<?php
/*
 * English for the Persian texts the original templates have hard-coded (rally point,
 * hero mansion, production overview, map legend, smithy, alliance news ...). Applied
 * to the page output only when the game runs in English (Session.php); the Persian
 * version of the game is untouched.
 *
 * Every key is matched as written and as HTML entities (&#1575;...), the way some
 * templates store it. Longer keys win (strtr), so a whole sentence is translated
 * before any word in it. Short or ambiguous words carry their surrounding markup.
 */
function lang_en_fixes() {
	return array(
		// rally point: movements
		'در حال حمله به ' => 'Attack on ',
		'در حال غارت ' => 'Raid on ',
		'نیروی کمکی به' => 'Reinforcement for',
		'بازگشت به ' => 'Return to ',
		'جاسوسی از' => 'Scouting',
		'حمله به' => 'Attack on',
		'$attack_type = "غارت"' => '$attack_type = "Raid on"',
		'لشگریان' => 'Troops',
		'زمان رسیدن' => 'Arrival',
		'<div class="in small">تا <span' => '<div class="in small">in <span',
		'</span> ساعت.</div>' => '</span> hrs.</div>',
		'</span> ساعت</div>' => '</span> hrs.</div>',
		'<div class="at small">در ' => '<div class="at small">at ',
		' ساعت</div>' => '</div>',
		'غنائم' => 'Bounty',
		'لغو حمله ( بازگشت نیرو ها طی 2 دقیقه )' => 'Cancel (the troops return within 2 minutes)',
		'ماجراجویی' => 'Adventure',
		'بنای دهکده ی جدید' => 'Founding a new village',
		// rally point: page
		'اردوگاه' => 'Rally point',
		'لشکریان دهکده‌ی شما در این محل جمع می شوند. از اینجا شما قادر به ارسال آنها برای غارت، حمله، تسخیر و یا پشتیبانی دهکده های دیگر می باشید' =>
			'The troops of your village gather here. From here you can send them to raid, attack, conquer or reinforce other villages',
		'دیدکلی' => 'Overview',
		'لشکرکشی' => 'Send troops',
		'شبیه ساز جنگی' => 'Combat simulator',
		'لیست فارم ها' => 'Farm list',
		'گریز از حمله هنگامی که حتی یک موج نیرو در حال برگشت باشد و فاصله زمانی آن کمتر از 10 ثانیه باشد عمل نخواهد کرد. و این گزینه تنها در پایتخت عمل میکنید' =>
			'Evasion does not work while troops are returning less than 10 seconds before the attack, and only in the capital',
		'درحال حاضر گریز فعال است و لشگریان شما گریز خواهند کرد' => 'Evasion is active: your troops will evade',
		'در حال حاضر گریز غیر فعال است و لشگریان شما در دهکده خواهند جنگید' => 'Evasion is inactive: your troops will fight in the village',
		'فعال سازی گریز برای دهکده پایتخت' => 'Activate evasion for the capital',
		'تنظیمات گریز' => 'Evasion settings',
		'گریز از حمله' => 'Evasion',
		'لیست فارم یکی از امکانات کلوپ طلایی می باشد و نیازی به پرداخت هزینۀ دیگری برای فعال سازی ان نیست' =>
			'The farm list is a Gold Club feature and needs no further payment',
		'عضو کلوپ طلایی شوید' => 'Join the Gold Club',
		'<span class="level">سطح ' => '<span class="level">Level ',
		// resources
		'انبار گندم' => 'Granary',
		'"انبار"' => '"Warehouse"',
		'چوب' => 'Wood',
		'خشت' => 'Clay',
		'آهن' => 'Iron',
		'گندم' => 'Crop',
		// building page (upgrade box)
		'ساختمان به سطح آخر رسیده' => 'The building is at its maximum level',
		'آخرین سطح ساختمان در حال ساخت است' => 'The last level of the building is being built',
		'ساختمان در حال تخریب است' => 'The building is being demolished',
		'مصرف گندم' => 'Crop consumption',
		'مدت زمان' => 'Duration',
		'کارگران مشغول کار هستند' => 'The workers are already at work',
		'کمبود غذا: اول گندم زار را ارتقا دهید' => 'Food shortage: upgrade a cropland first',
		'انبار غذا را ارتقا بده' => 'Upgrade your granary',
		'انبار را ارتقا بده' => 'Upgrade your warehouse',
		'"none\">تامین منابع ' => '"none\">Enough resources ',
		'"none">تامین منابع ' => '"none">Enough resources ',
		' در  ' => ' at ',
		'قرار دادن در نوبت ساخت' => 'add to the building queue',
		'تخریب' => 'Demolish',
		'آسیاب' => 'Grain mill',
		'دهکده پایتخت است' => 'This village is the capital',
		'تاسیس شد' => '',
		'تاسیس' => 'Found',
		'اموزش' => 'Train',
		'(موجود: ' => '(available: ',
		'پذیرفتن پیشنهاد' => 'Accept offer',
		'تکرار:' => 'Repeat:',
		'تحقیق کردن' => 'Research',
		'جزئیات بیشتر' => 'More details',
		'امتیاز فرهنگی' => 'Culture points',
		'<td>سرباز</td>' => '<td>Unit</td>',
		'تمام شده در' => 'Done at',
		'پیش نیازها' => 'Prerequisites',
		'یش نیازها' => 'Prerequisites',
		'تله ساز' => 'Trapper',
		'شما دارید' => 'You have',
		'تا تله که از این ها' => 'traps, of which',
		'تله شما در حال حاضر پر است' => 'are occupied right now',
		'تله' => 'Traps',
		'حداکثر' => 'max.',
		// smithy
		'تولید گندم شما منفی است و هیچگاه منابع کافی نخواهید داشت' => 'Your crop production is negative, you will never have enough resources',
		'سطح اهنگری را افزایش دهید' => 'Upgrade the smithy',
		'درحال حاظر یک سطح از نیرو ها در حال افزایش است' => 'An upgrade is already in progress',
		'بالا بردن سطح' => 'Upgrade',
		'نوع نیرو' => 'Unit',
		'<td>زمان</td>' => '<td>Duration</td>',
		// hero mansion / hero
		'باعث افزایش قدرت دفاعی تمامی لشکریانی خواهد شد که همراه قهرمان می‌باشند' => 'Increases the defence of all troops accompanying the hero',
		'امتیاز دفاعی' => 'Defence bonus',
		'باعث افزایش تولید منابع دهکده‌ای خواهد شد که قهرمان در حال حاضر در آن مقیم است' => 'Increases the resource production of the village the hero lives in',
		'افزایش تولید منابع' => 'Resource production bonus',
		'تغییر نوع تولید منابع از طرف قهرمان' => 'Change the resources the hero produces',
		'تمامی منابع' => 'All resources',
		'منابع' => 'Resources',
		'بازسازی قهرمان شما' => 'Your hero regenerates',
		'برای هر روز' => 'per day',
		'در روز از قهرمان' => 'per day',
		'سلامتی' => 'Health',
		'برای رسیدن به Szint' => 'To reach level',
		'قهرمان شما به' => 'your hero needs',
		'تجربه نیاز دارد' => 'experience',
		'تجربه' => 'Experience',
		'Szint قهرمان شما' => 'Your hero\'s level',
		'Szint قهرمان' => 'Hero level',
		'قدرت هجومی قهرمان شما برای هر امتیازی که به آن داده می‌شود به جای اینکه به میزان 80 افزایش یابد به میزان 100 افزایش خواهد یافت' =>
			'Each point raises your hero\'s fighting strength by 100 instead of 80',
		'قهرمان در هیچکدام از دهکده های شما نیست' => 'The hero is not in any of your villages',
		'قهرمان شما' => 'Your hero',
		'قهرمان' => 'Hero',
		'alt="حذف"' => 'alt="Delete"',
		// production overview
		'تولیدات تقریبا تا' => 'production bonus for about',
		'ساعت دیگر ادامه دارد' => 'more hours',
		'تولیدات ساختمان ها و آبادی ها' => 'Production of fields and oases',
		'جمعیت و صف ساخت ساختمان' => 'Population and building queue',
		'گندم آزاد' => 'Free crop',
		'ساختمان های در صف ساخت' => 'Buildings in the queue',
		'غیر فعال' => 'inactive',
		'غیرفعال' => 'inactive',
		'تولیدات' => 'Production',
		'مصرف سرباز های غیر خودی' => 'Upkeep of foreign troops',
		'مصرف سرباز های خودی' => 'Upkeep of own troops',
		'- در دهکده' => '- in the village',
		'- در آبادی' => '- in oases',
		'- در راه' => '- on the way',
		'زندانی ها' => 'Prisoners',
		'جایزه کتیبه ها' => 'Artefact bonus',
		'تعادل گندم' => 'Crop balance',
		'جایزه' => 'Medal',
		// map legend and tooltips
		"'بازیکن'" => "'Player'",
		"'جمعیت'" => "'Population'",
		"'اتحاد'" => "'Alliance'",
		"'نژاد'" => "'Tribe'",
		"'دهکده'" => "'Village'",
		'آبادی تسخیر نشده' => 'Unoccupied oasis',
		'آبادی تسخیر شده' => 'Occupied oasis',
		'دهکده تسخیر نشده' => 'Abandoned valley',
		'رومن ها' => 'Romans',
		'توتن ها' => 'Teutons',
		'گول ها' => 'Gauls',
		'ناتار ها' => 'Natars',
		'طبیعت' => 'Nature',
		'رومن' => 'Romans',
		'توتن' => 'Teutons',
		'گول' => 'Gauls',
		'حرکت به چپ' => 'Move left',
		'حرکت به راست' => 'Move right',
		'حرکت به بالا' => 'Move up',
		'حرکت به پایین' => 'Move down',
		'تمام صفحه' => 'Full screen',
		'شمال' => 'North',
		'شرق' => 'East',
		'جنوب' => 'South',
		'غرب' => 'West',
		'>بازیکن:<' => '>Player:<',
		'>جمعیت:<' => '>Population:<',
		'>اتحاد:<' => '>Alliance:<',
		'>جزئیات<' => '>Details<',
		// sidebars, page frame
		'مشاهده اطلاعات بیشتر' => 'Show more information',
		'پنهان کردن اطلاعات' => 'Hide information',
		'نشان ندادن پیام' => 'Hide message',
		'نشان دادن پیام' => 'Show message',
		'سرباز خانه بنا شده است' => 'The barracks have been built',
		'اصطبل بنا شده است' => 'The stable has been built',
		'کارگاه بنا شده است' => 'The workshop has been built',
		'اسکریپت نمایشی' => 'Demo script',
		'برای مشاهده اطلاعات اسکریپت' => 'Script information',
		'آخرین تغییرات' => 'latest changes',
		'نقشه های ساخت آزاد شدند' => 'The building plans have been released',
		"'بستن'" => "'close'",
		'راهنمای سریع' => 'Quick help',
		'مطابق با جستجوی شما نتیجه ای یافت نشد' => 'Nothing matches your search',
		'بیشتر بدانید' => 'Learn more',
		'برنامه نویس, دیباگر و پشتیبان فنی اسکریپت' => 'Script programming, debugging and support',
		'مدیریت و فروش' => 'Management and sales',
		'تیم مرجع بازی های آنلاین' => 'Online games team',
		'مشخصات بازیکن' => 'Player profile',
		'(پایتخت)' => '(capital)',
		'title="تعداد: ' => 'title="Amount: ',
		'حملات' => 'Attacks',
		// gold club farm list
		'سربازی انتخاب نکرده اید' => 'You have not selected any troops',
		'شما حداکثر' => 'You can have at most',
		'فارم می توانید در یک لیست داشته باشید' => 'farms in one list',
		'در هر لیست تا 50 حمله می توان  داشت و به ازای هر سطح از اردوگاه 50 حمله اضافه می شود' =>
			'A list holds 50 raids, plus 50 for every level of the rally point',
		'یک دهکده را انتخاب کنید' => 'Select a village',
		'فارمی وجود ندارد' => 'No farms',
		'تعداد فارم' => 'Number of farms',
		'از 0 تا' => 'from 0 to',
		'>فارم (' => '>Farm (',
		'ویرایش' => 'Edit',
		'برای مدیریت فارم ها' => 'To manage farms',
		'">اینجا</a> کلیک کنید' => '">click here</a>',
		'">کلیک کنید</a>' => '">click here</a>',
		'عملیات انجام شد' => 'Done',
		// gold
		'طلای کافی برای فعال کردن این ویژگی ندارید' => 'You do not have enough gold for this feature',
		'آیا میخواهید طلا بخرید؟' => 'Do you want to buy gold?',
		'طلا بخرید' => 'Buy gold',
		'انتخاب یک بسته دیگر' => 'Choose another package',
		'>طلا<' => '>Gold<',
		// messages from the engine (form errors, alliance news)
		'روز صلح است' => 'It is a day of peace',
		'نامه نمی تواند بدون موضوع باشد' => 'A message needs a subject',
		'کد امنیتی اشتباه می باشد' => 'Wrong security code',
		'کد امنیتی صحیح نیست' => 'Wrong security code',
		'مولتی اکانت مجاز نیست' => 'Multi-accounts are not allowed',
		'</a> بازیکن  <a' => '</a> invited <a',
		'</a> بازیکن <a' => '</a> expelled <a',
		'</a> را به اتحاد دعوت کرد' => '</a> to the alliance',
		'</a> را اخراج کرد' => '</a>',
		'دعوت را پس گرفت' => 'withdrew an invitation',
		'به اتحادیه پیوست' => 'joined the alliance',
		'اتحاد توسط' => 'The alliance was founded by',
		'نام اتحاد را تغییر داد' => 'changed the alliance name',
		'توضیحات اتحاد را تغییر داد' => 'changed the alliance description',
		'دسترسی ها را تغییر داد' => 'changed the permissions',
		'اتحادیه را ترک کرد' => 'left the alliance',
		'قبلا دعوتنامه فرستاده شده بود' => 'An invitation was already sent',
		'دعوتنامه فرستاده شد' => 'Invitation sent',
		'انتخاب اشتباه ساخته شده است' => 'Wrong choice',
		'نمیتوانید دعوت کنید' => 'You cannot invite',
		'اتحاد وجود ندارد' => 'The alliance does not exist',
		'Szint' => 'Level',
	);
}

// The dictionary with every key also in its &#NNNN; form (built once per request)
function lang_en_fixes_table() {
	static $table = null;
	if($table !== null) return $table;
	$table = array();
	foreach(lang_en_fixes() as $fa => $en) {
		$table[$fa] = $en;
		$enc = preg_replace_callback('/[\x{0600}-\x{06FF}\x{200C}]/u', function($m) {
			$c = unpack('N', mb_convert_encoding($m[0], 'UCS-4BE', 'UTF-8'));
			return '&#'.$c[1].';';
		}, $fa);
		if($enc !== $fa) $table[$enc] = $en;
	}
	return $table;
}

// ob_start() callback: HTML, JSON and scripts only (never an image)
function lang_en_fix_output($buffer) {
	foreach(headers_list() as $h) {
		if(stripos($h, 'content-type:') === 0 && !preg_match('#text/|json|javascript#i', $h)) return $buffer;
	}
	// byte check: a page with invalid UTF-8 would make a /u pattern fail
	if(strpos($buffer, '&#1') === false && !preg_match('/[\xD8-\xDB][\x80-\xBF]/', $buffer)) return $buffer;
	return strtr($buffer, lang_en_fixes_table());
}
?>
