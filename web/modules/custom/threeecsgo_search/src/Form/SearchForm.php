<?php
namespace Drupal\threeecsgo_search\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\file\Entity\File;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;

/**
 * Class SearchForm.
 *
 * @package Drupal\threeecsgo_search\Form
 */
class SearchForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'search_user_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['steamid'] = [
      '#type'     => 'textfield',
      '#title'    => $this->t('SteamID'),
      '#required' => TRUE,
    ];

    $form['submit'] = [
      '#type'  => 'submit',
      '#value' => $this->t('Search'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    if (empty($form_state->getValue('steamid')) or !(is_numeric($form_state->getValue('steamid')))) {
      $form_state->setErrorByName('steamid', $this->t('Not blank and Numeric'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    global $base_url;

    $steamid = $form_state->getValue('steamid');

    $url_api_steam_1 = "https://api.steampowered.com/ISteamUser/GetPlayerSummaries/v2/?key=28ECE97465C977305C7D06CBBA0DE695&steamids=" . $steamid;
    $content_url_1 = file_get_contents($url_api_steam_1);
    $json_steam_data = json_decode($content_url_1);

    if ($json_steam_data->response->players[0] != null) {
      $personaname = $json_steam_data->response->players[0]->personaname;

      $username_drupal = strtolower(str_replace(' ', '', preg_replace('([^A-Za-z0-9])', '', $personaname)));

      $user_register = user_load_by_name($username_drupal);

      if ($user_register == NULL) {
        $realname = $json_steam_data->response->players[0]->realname;
        $primaryclanid = $json_steam_data->response->players[0]->primaryclanid;
        $avatar = $json_steam_data->response->players[0]->avatarfull;
        $tmp = 'public://tmp/avatar.jpeg';
        file_put_contents($tmp, file_get_contents($avatar));

        $url_api_steam_2 = "http://api.steampowered.com/ISteamUserStats/GetUserStatsForGame/v0002/?appid=730&key=28ECE97465C977305C7D06CBBA0DE695&steamid=" . $steamid;
        $content_url_2 = file_get_contents($url_api_steam_2);
        $json_steam_stats = json_decode($content_url_2);

        $stats = $json_steam_stats->playerstats->stats;

        // Create user object.
        $user = User::create();

        //Mandatory settings
        //$user->setPassword("password");
        $user->enforceIsNew();
        //$user->setEmail("email");
        $user->setUsername($username_drupal);
        //$user->addRole('authenticated');
        $user->{'steamid'}->setValue($steamid);
        $user->{'personaname'}->setValue($personaname);
        $user->{'primaryclanid'}->setValue($primaryclanid);

        if ($realname != NULL) {
          $user->{'realname'}->setValue($realname);
        }
        else {
          $user->{'realname'}->setValue('No name');
        }

        // Create file object from a locally copied file.
        $uri = file_unmanaged_copy($avatar, 'public://avatars/' . $steamid . '.jpg', FILE_EXISTS_REPLACE);
        $file = File::Create([
          'uri' => $uri,
        ]);
        $file->save();

        // Attach file in node.
        $user->avatarfull->setValue([
          'target_id' => $file->id(),
        ]);

        foreach ($stats as $stat) {
          if ($stat->name == "total_deaths" or $stat->name == "total_kills" or $stat->name == "total_time_played"
            or $stat->name == "total_wins" or $stat->name == "total_kills_headshot" or $stat->name == "total_mvps"
            or $stat->name == "total_rounds_played" or $stat->name == "total_shots_fired" or $stat->name == "total_shots_hit") {
            if ($stat->name == "total_time_played") {
              $user->{$stat->name}->setValue(($stat->value / 60) / 60);
            }
            else {
              $user->{$stat->name}->setValue($stat->value);
            }
          }
        }

        $user->activate();
        $user->save();

        $this->create_inventory($username_drupal);

        $form_state->setRedirectUrl(Url::fromUri($base_url . '/player/' . $user->id()));
      }
      else {
        $form_state->setRedirectUrl(Url::fromUri($base_url . '/player/' . $user_register->id()));
      }
    }
  }

  public function create_inventory($username_drupal) {
    $user = user_load_by_name($username_drupal);
    $url_api_steam_3 = "http://steamcommunity.com/profiles/" . $user->{'steamid'}->value . "/inventory/json/730/2";
    $content_url_3 = file_get_contents($url_api_steam_3);
    $json_steam_inventory = json_decode($content_url_3);
    $inventory = $json_steam_inventory->rgDescriptions;

    foreach ($inventory as $article) {
      if (strpos(strtoupper($article->market_name), 'CAJA') != true and strpos(strtoupper($article->market_name), 'LLAVE') != true) {
        $node_inventory = Node::create([
          'title' => $user->getUsername() . " - " . $article->market_name,
          'type' => 'article',
          'status' => 1,
        ]);

        // Create file object from a locally copied file.
        $uri = file_unmanaged_copy("https://steamcommunity-a.akamaihd.net/economy/image/" . $article->icon_url, 'public://inventory/' . $article->market_name . '.jpg', FILE_EXISTS_REPLACE);
        $file = File::Create([
          'uri' => $uri,
        ]);
        $file->save();

        // Attach file in node.
        $node_inventory->image_article->setValue([
          'target_id' => $file->id(),
        ]);

        $node_inventory->{'owner_article'}->setValue($user->id());

        $node_inventory->save();
      }
    }
  }
}
